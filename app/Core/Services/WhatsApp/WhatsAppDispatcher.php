<?php

namespace App\Core\Services\WhatsApp;

use App\Core\Models\WhatsAppTemplate;
use App\Modules\Crm\Models\Creative;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\LeadService;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\CustomerMessage;
use Illuminate\Support\Facades\DB;

/**
 * Sends prepared messages through Interakt while it is switched on. Every message is first
 * saved as "prepared" (the share-link state); a message whose kind has an approved template
 * then goes by API. No template, or Interakt refuses it → it stays in "Messages to send"
 * with the reason, and a person sends it with the share link. Nothing is lost.
 */
class WhatsAppDispatcher
{
    public function __construct(protected InteraktChannel $interakt) {}

    /**
     * Called right after a message is saved: due messages go once the transaction commits
     * (a job change that rolls back sends nothing); later ones go with whatsapp:send-due.
     */
    public function queue(CustomerMessage $message): void
    {
        if (!WhatsAppSettings::apiOn() || $message->status !== CustomerMessage::PREPARED) {
            return;
        }
        if ($message->scheduled_at && $message->scheduled_at->isFuture()) {
            return;
        }

        DB::afterCommit(fn () => $this->send($message));
    }

    public function send(CustomerMessage $message): CustomerMessage
    {
        if ($message->status !== CustomerMessage::PREPARED || !$message->phone || $message->provider_message_id) {
            return $message;
        }

        $template = $this->templateFor($message);
        if (!$template) {
            $message->update(['error' => 'No WhatsApp template is set for this message: send it from the workshop phone.']);

            return $message;
        }

        $values = $message->params ?? [];
        $values['body'] ??= $message->body;
        $body = array_map(fn ($param) => $this->clean($values[$param] ?? ''), $template->body_params ?? []);
        $header = $template->header_type !== 'none' && $message->media_url ? [$message->media_url] : [];
        if ($template->header_type !== 'none' && !$header) {
            $message->update(['error' => 'The WhatsApp template needs a picture / video / file, but this message has none.']);

            return $message;
        }
        $buttons = $template->button_param ? ['0' => [$this->buttonValue((string) ($values[$template->button_param] ?? ''))]] : [];

        $result = $this->interakt->sendTemplate($message->phone, $template->provider_name, $template->language, $body, $header, $buttons, (string) $message->id);

        if ($result['ok']) {
            $message->update([
                'status' => CustomerMessage::SENT,
                'channel' => $this->interakt->name(),
                'provider_message_id' => $result['id'],
                'error' => null,
                'sent_at' => now(),
            ]);
            if ($message->lead_id) {
                app(LeadService::class)->log($message->lead_id, 'whatsapp', null, mb_strimwidth($message->body, 0, 400, '…'), null, ['via' => 'interakt']);
            }
        } else {
            $message->update(['error' => $result['error']]);
        }

        return $message;
    }

    /**
     * Scheduled messages that have come due (journeys, reminders), made after the API was
     * switched on and not tried yet.
     */
    public function sendDue(int $limit = 200): int
    {
        if (!WhatsAppSettings::apiOn()) {
            return 0;
        }

        $sent = 0;
        CustomerMessage::where('status', CustomerMessage::PREPARED)
            ->whereNull('error')
            ->whereNull('provider_message_id')
            ->whereNotNull('phone')
            ->where('scheduled_at', '<=', now())
            ->when(WhatsAppSettings::enabledAt(), fn ($q, $at) => $q->where('created_at', '>=', \Carbon\Carbon::parse($at)))
            ->where('created_at', '>=', now()->subDays(3))
            ->orderBy('scheduled_at')
            ->limit($limit)
            ->get()
            ->each(function (CustomerMessage $m) use (&$sent) {
                if ($this->send($m)->status === CustomerMessage::SENT) {
                    $sent++;
                }
            });

        return $sent;
    }

    /**
     * The template for a message: its kind's row (a creative has its own row, else its
     * template name with no values).
     */
    public function templateFor(CustomerMessage $message): ?WhatsAppTemplate
    {
        $key = $message->creative_id ? "creative:{$message->creative_id}" : $message->template;
        $row = WhatsAppTemplate::where('key', $key)->first();
        if ($row) {
            return $row->isMapped() ? $row : null;
        }

        if ($message->creative_id) {
            $creative = Creative::find($message->creative_id);
            if ($creative?->wa_template) {
                return new WhatsAppTemplate([
                    'key' => $key,
                    'label' => $creative->title,
                    'provider_name' => $creative->wa_template,
                    'language' => $creative->wa_language ?: 'en',
                    'body_params' => [],
                    'header_type' => $message->media_url ? match ($creative->kind) { 'image', 'video' => $creative->kind, default => 'document' } : 'none',
                    'is_active' => true,
                ]);
            }
        }

        return null;
    }

    /**
     * The placeholder values worth keeping on the message (short text only).
     *
     * @return array<string, string>
     */
    public static function storableParams(array $values): array
    {
        return collect($values)
            ->filter(fn ($v, $k) => is_string($k) && (is_scalar($v) || $v instanceof \Stringable))
            ->map(fn ($v) => mb_substr((string) $v, 0, 500))
            ->all();
    }

    // ------------------------------------------------------------------ webhook

    /**
     * Interakt webhook: delivery status of our messages, and customers' replies.
     */
    public function handleWebhook(array $payload): string
    {
        $type = (string) ($payload['type'] ?? '');
        $data = $payload['data'] ?? [];
        $msg = $data['message'] ?? [];

        if (str_starts_with($type, 'message_api_')) {
            $message = $this->findMessage($msg);
            if (!$message) {
                return 'unknown message';
            }
            $at = now();
            match ($type) {
                'message_api_sent' => $message->status === CustomerMessage::PREPARED
                    ? $message->update(['status' => CustomerMessage::SENT, 'sent_at' => $message->sent_at ?? $at, 'error' => null])
                    : null,
                'message_api_delivered' => $message->update(['delivered_at' => $message->delivered_at ?? $at]),
                'message_api_read' => $message->update(['delivered_at' => $message->delivered_at ?? $at, 'read_at' => $message->read_at ?? $at]),
                'message_api_failed' => $message->update([
                    // Back to the queue so a person can send it by hand
                    'status' => CustomerMessage::PREPARED,
                    'sent_at' => null,
                    'error' => 'Interakt could not deliver it: ' . mb_strimwidth((string) ($msg['channel_failure_reason'] ?? $msg['message_status'] ?? 'failed'), 0, 200, '…'),
                ]),
                default => null,
            };

            return $type;
        }

        if ($type === 'message_received') {
            return $this->reply($data);
        }

        return 'ignored';
    }

    protected function findMessage(array $msg): ?CustomerMessage
    {
        if (!empty($msg['id'])) {
            $found = CustomerMessage::where('provider_message_id', (string) $msg['id'])->first();
            if ($found) {
                return $found;
            }
        }
        $callback = $msg['callback_data'] ?? $msg['meta_data']['callback_data'] ?? null;

        return is_numeric($callback) ? CustomerMessage::find((int) $callback) : null;
    }

    /**
     * A customer wrote back: noted on their open lead; "STOP" ends marketing messages.
     */
    protected function reply(array $data): string
    {
        $phone = ShareLinkChannel::normalizePhone(($data['customer']['country_code'] ?? '') . ($data['customer']['phone_number'] ?? ''))
            ?? ShareLinkChannel::normalizePhone($data['customer']['channel_phone_number'] ?? null);
        if (!$phone) {
            return 'no phone';
        }
        $ten = substr($phone, -10);
        $text = trim((string) ($data['message']['message'] ?? ''));

        $lead = Lead::whereIn('status', Lead::OPEN)->where('phone', 'like', "%{$ten}")->latest('id')->first();
        if ($lead) {
            app(LeadService::class)->log($lead, 'reply', null, mb_strimwidth($text !== '' ? $text : '(media)', 0, 1000, '…'), null, ['via' => 'interakt']);
        }

        if (preg_match('/^\s*(stop|unsubscribe)\s*$/i', $text)) {
            $customers = Customer::where(fn ($q) => $q->whereRaw("REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', '') LIKE ?", ["%{$ten}"])
                ->orWhereRaw("REPLACE(REPLACE(REPLACE(COALESCE(whatsapp_phone, ''), ' ', ''), '-', ''), '+', '') LIKE ?", ["%{$ten}"]))->whereNull('marketing_opt_out_at');
            $customers->update(['marketing_opt_out_at' => now()]);
            Lead::where('phone', 'like', "%{$ten}")->whereNull('opted_out_at')->update(['opted_out_at' => now()]);

            return 'opted out';
        }

        return $lead ? 'reply logged' : 'reply';
    }

    /**
     * WhatsApp refuses template values with new lines, tabs or long runs of spaces, or empty ones.
     */
    protected function clean(mixed $value): string
    {
        $text = trim(preg_replace(['/[\r\n\t]+/', '/ {4,}/'], [' · ', '   '], (string) $value));

        return $text === '' ? '-' : mb_substr($text, 0, 1000);
    }

    /**
     * A dynamic URL button holds the part after the template's base URL: when the template's
     * URL is "<this app's address>/{{1}}", the link's path goes in.
     */
    protected function buttonValue(string $value): string
    {
        $base = (\App\Core\Support\PublicUrl::base() ?? rtrim((string) config('app.url'), '/')) . '/';

        return str_starts_with($value, $base) ? substr($value, strlen($base)) : $value;
    }
}
