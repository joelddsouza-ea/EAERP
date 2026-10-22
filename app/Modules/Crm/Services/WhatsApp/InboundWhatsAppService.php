<?php

namespace App\Modules\Crm\Services\WhatsApp;

use App\Core\Models\User;
use App\Core\Services\WhatsApp\WhatsAppDispatcher;
use App\Core\Support\WorkshopSettings;
use App\Modules\Crm\Models\Campaign;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\WhatsAppChatMessage as Msg;
use App\Modules\Crm\Models\WhatsAppConversation as Conv;
use App\Modules\Crm\Services\LeadService;
use App\Modules\Workshop\Notifications\StaffAlert;

/**
 * A customer's WhatsApp message (Interakt "message_received"):
 *  - it joins the number's chat in the CRM inbox (text, photo, document, voice note, video,
 *    or the reply to buttons / a list);
 *  - "STOP" / "unsubscribe" ends marketing messages;
 *  - a number with an open lead: noted on the lead, its executive told;
 *  - a customer with an open job: the job's advisor told;
 *  - anyone else (with "new chats become leads" on): a new lead, source "WhatsApp chat", or
 *    the Click-to-WhatsApp campaign whose ready-made first message it starts with; assigned
 *    round-robin like every lead.
 */
class InboundWhatsAppService
{
    /** A bell at most this often per chat while messages keep coming */
    public const BELL_EVERY_MINUTES = 15;

    public function __construct(
        protected WhatsAppInboxService $inbox,
        protected LeadService $leads,
    ) {}

    public function handle(array $data): string
    {
        $phone = WhatsAppDispatcher::senderPhone($data);
        if (!$phone) {
            return 'no phone';
        }
        $customer = $data['customer'] ?? [];
        $msg = $data['message'] ?? [];
        [$kind, $text] = self::content($msg);

        $conv = $this->inbox->conversation($phone, $customer['traits']['name'] ?? null);
        $isNew = !$conv->messages()->exists();
        $quietSince = $conv->last_inbound_at;

        Msg::create([
            'conversation_id' => $conv->id,
            'direction' => Msg::IN,
            'kind' => $kind,
            'body' => $text,
            'media_url' => $msg['media_url'] ?? null,
            'file_name' => $msg['file_name'] ?? null,
            'provider_message_id' => isset($msg['id']) ? (string) $msg['id'] : null,
            'status' => 'received',
            'raw' => ['message' => array_diff_key($msg, ['raw_template' => 1]), 'customer_id' => $customer['id'] ?? null],
        ]);

        $referral = self::referral($data);
        $conv->forceFill([
            'first_text' => $conv->first_text ?? ($text !== null ? mb_substr($text, 0, 1000) : null),
            'referral' => $conv->referral ?? $referral,
            'interakt_customer_id' => $conv->interakt_customer_id ?? (isset($customer['id']) ? (string) $customer['id'] : null),
        ])->save();
        $this->inbox->touch($conv, $text ?? self::kindLabel($kind), inbound: true);

        if ($text !== null && WhatsAppDispatcher::isStop($text)) {
            WhatsAppDispatcher::optOut($phone);
            $this->inbox->link($conv);
            if ($conv->lead_id) {
                $this->leads->log($conv->lead_id, 'reply', null, 'Asked to stop WhatsApp messages', null, ['via' => 'interakt']);
            }

            return 'opted out';
        }

        $this->inbox->link($conv);
        $result = 'reply';

        if ($conv->lead_id) {
            $this->leads->log($conv->lead_id, 'reply', null, mb_strimwidth($text ?? '(' . self::kindLabel($kind) . ')', 0, 1000, '…'), null, ['via' => 'interakt']);
            $this->leads->bumpScore(Lead::find($conv->lead_id), 5);
            $result = 'reply logged';
        } elseif (!$conv->customer_id || !WhatsAppInboxService::openJob($conv->customer_id)) {
            // Someone new, or a customer with no job in the workshop: a lead to follow up
            if (WorkshopSettings::get('crm.whatsapp.auto_lead') && ($isNew || !$this->recentlyClosedLead($phone))) {
                // The executive gets the usual "new lead" bell from the round-robin
                $lead = $this->inbox->createLead($conv->fresh(), null, $this->campaignFor($conv->first_text ?? $text)?->id);
                if ($lead->assigned_to && ($to = User::find($lead->assigned_to))) {
                    $this->inbox->assignInInterakt($conv->fresh(), $to);
                }

                return 'lead created';
            }
        }

        $this->bell($conv->fresh(), $text ?? self::kindLabel($kind), $quietSince);

        return $result;
    }

    /**
     * The campaign whose Click-to-WhatsApp ad starts the chat with this text (the ad's
     * ready-made message), else none.
     */
    public function campaignFor(?string $text): ?Campaign
    {
        $text = mb_strtolower(trim((string) $text));
        if ($text === '') {
            return null;
        }

        return Campaign::where('is_active', true)->whereNotNull('whatsapp_start_text')->where('whatsapp_start_text', '!=', '')->get()
            ->first(fn (Campaign $c) => str_starts_with($text, mb_strtolower(trim($c->whatsapp_start_text)))
                || str_contains($text, mb_strtolower(trim($c->whatsapp_start_text))));
    }

    /**
     * What kind of message it is and its text: plain text, a caption, the chosen button or list row.
     *
     * @return array{0: string, 1: ?string}
     */
    public static function content(array $msg): array
    {
        $type = strtolower((string) ($msg['message_content_type'] ?? 'text'));
        $raw = $msg['message'] ?? null;
        $text = is_string($raw) ? $raw : null;

        // Interactive replies and some media come as a JSON string
        if (is_string($raw) && str_starts_with(ltrim($raw), '{')) {
            $json = json_decode($raw, true);
            if (is_array($json)) {
                $text = $json['button_reply']['title'] ?? $json['list_reply']['title'] ?? $json['text'] ?? $json['caption'] ?? $json['body'] ?? $json['title'] ?? $text;
            }
        } elseif (is_array($raw)) {
            $text = $raw['text'] ?? $raw['caption'] ?? $raw['title'] ?? null;
        }

        $kind = match (true) {
            str_contains($type, 'image') => 'image',
            str_contains($type, 'video') => 'video',
            str_contains($type, 'audio') || str_contains($type, 'voice') => 'audio',
            str_contains($type, 'document') || str_contains($type, 'file') => 'document',
            str_contains($type, 'button') || str_contains($type, 'interactive') || str_contains($type, 'list') => 'button',
            str_contains($type, 'text') => 'text',
            default => 'other',
        };
        $text = $text !== null ? trim($text) : null;

        return [$kind, $text === '' ? null : $text];
    }

    /**
     * Anything Interakt passes about where the chat started (Click-to-WhatsApp ads). Interakt
     * doesn't document these fields, so whatever looks like it is kept as is.
     */
    public static function referral(array $data): ?array
    {
        $found = [];
        $walk = function ($node, string $path) use (&$walk, &$found) {
            if (!is_array($node) || count($found) > 20) {
                return;
            }
            foreach ($node as $k => $v) {
                $key = strtolower((string) $k);
                if (preg_match('/referral|ctwa|source_url|source_id|ad_id|headline|campaign/', $key) && $v !== null && $v !== '' && $v !== []) {
                    $found[ltrim("{$path}.{$k}", '.')] = is_scalar($v) ? mb_substr((string) $v, 0, 500) : $v;
                } elseif (is_array($v)) {
                    $walk($v, "{$path}.{$k}");
                }
            }
        };
        $walk($data, '');
        unset($found['message.campaign_id']);

        return $found ?: null;
    }

    public static function kindLabel(string $kind): string
    {
        return match ($kind) {
            'image' => 'Photo',
            'video' => 'Video',
            'audio' => 'Voice note',
            'document' => 'Document',
            'button' => 'Button reply',
            default => 'Message',
        };
    }

    /** A lead from this number closed in the last week: "thanks" shouldn't open a new one */
    protected function recentlyClosedLead(string $phone): bool
    {
        return Lead::where('phone', 'like', '%' . substr($phone, -10))->whereNotIn('status', Lead::OPEN)
            ->where('updated_at', '>', now()->subDays(7))->exists();
    }

    protected function bell(Conv $conv, string $text, $quietSince): void
    {
        if (!$conv->assigned_to || ($quietSince && $quietSince->gt(now()->subMinutes(self::BELL_EVERY_MINUTES)))) {
            return;
        }
        User::find($conv->assigned_to)?->notify(new StaffAlert(
            'whatsapp_message',
            'WhatsApp — ' . ($conv->name ?? $conv->phone),
            mb_strimwidth($text, 0, 160, '…'),
            "/crm/whatsapp?c={$conv->id}",
        ));
    }
}
