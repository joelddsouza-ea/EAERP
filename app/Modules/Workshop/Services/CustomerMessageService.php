<?php

namespace App\Modules\Workshop\Services;

use App\Core\Models\User;
use App\Core\Services\WhatsApp\ShareLinkChannel;
use App\Core\Services\WhatsApp\WhatsAppChannel;
use App\Core\Services\WhatsApp\WhatsAppDispatcher;
use App\Modules\Workshop\Models\CustomerMessage;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\WorkflowStage;

/**
 * Builds customer messages from lang/en/customer_messages.php, logs them, and
 * hands them to the WhatsApp channel. With the share channel they wait as
 * "prepared" until an advisor sends them from the workshop phone.
 */
class CustomerMessageService
{
    public function __construct(
        protected WhatsAppChannel $channel,
        protected CustomerLinkService $links,
    ) {}

    public function prepare(JobCard $jobCard, string $template, array $params = [], ?User $user = null): CustomerMessage
    {
        return $this->prepareText($jobCard, $template, null, $params, $user);
    }

    /**
     * A message from given text (e.g. a stage message the owner edited) instead of
     * the language file. Placeholders as in the language file.
     */
    public function prepareText(JobCard $jobCard, string $template, ?string $text, array $params = [], ?User $user = null): CustomerMessage
    {
        $jobCard->loadMissing(['customer', 'vehicle', 'branch.organization']);
        $customer = $jobCard->customer;

        $replace = [
            'name' => $customer->name,
            'car' => $jobCard->vehicle?->registration_no ?? '',
            'job' => $jobCard->job_number,
            'workshop' => $jobCard->branch?->organization?->name ?? config('app.name'),
            'link' => $this->links->ensure($jobCard, $user)->url(),
            ...$params,
        ];

        $body = $text === null
            ? __("customer_messages.{$template}", $replace)
            : self::fill($text, $replace);

        $phone = ShareLinkChannel::normalizePhone($customer->whatsapp_phone ?: $customer->phone);

        $message = new CustomerMessage([
            'job_card_id' => $jobCard->id,
            'customer_id' => $customer->id,
            'template' => $template,
            'body' => $body,
            'params' => WhatsAppDispatcher::storableParams($replace),
            'phone' => $phone,
            'channel' => $this->channel->name(),
            'prepared_by' => $user?->id,
        ]);

        if (!$phone) {
            $message->status = CustomerMessage::FAILED;
            $message->error = 'The customer has no valid mobile number for WhatsApp.';
        } else {
            // Waits for the share link; with Interakt on it goes by API when its kind has a template
            $message->status = CustomerMessage::PREPARED;
        }

        $message->save();
        app(WhatsAppDispatcher::class)->queue($message);

        return $message;
    }

    /**
     * Message for a status the customer is told about (config customer_channel.status_templates).
     */
    /**
     * @param string|null $extra a line added at the end (e.g. new photos/videos), so one message goes, not two
     */
    public function prepareForStatus(JobCard $jobCard, string $status, ?User $user = null, ?string $extra = null): ?CustomerMessage
    {
        $text = trim((string) WorkflowStage::for($status)?->message);

        if ($text === '') {
            $template = config("workshop.customer_channel.status_templates.{$status}");
            if (!$template) {
                return null;
            }
            $text = trans("customer_messages.{$template}");
        }

        if ($extra) {
            $text .= "\n" . $extra;
        }

        $params = [...$this->billingParams($jobCard), ...app(TripService::class)->messageParams($jobCard)];

        return $this->prepareText($jobCard, "stage:{$status}", $text, $params, $user);
    }

    /**
     * :amount, :balance and :gate_pass for stage messages.
     */
    protected function billingParams(JobCard $jobCard): array
    {
        $summary = app(\App\Modules\Billing\Services\BillingService::class)->summary($jobCard);

        return [
            'amount' => number_format((float) ($summary['bill_total'] ?? 0), 0),
            'balance' => number_format(max(0, (float) $summary['balance']), 0),
            'gate_pass' => $summary['gate_pass']['number'] ?? '',
        ];
    }

    /**
     * Replace :placeholders (longest first, so :name never eats :name_x).
     */
    public static function fill(string $text, array $replace): string
    {
        uksort($replace, fn ($a, $b) => strlen($b) <=> strlen($a));

        foreach ($replace as $key => $value) {
            $text = str_replace(':' . $key, (string) $value, $text);
        }

        return $text;
    }

    public function shareUrl(CustomerMessage $message): ?string
    {
        return $message->phone ? $this->channel->shareUrl($message->phone, $message->body) : null;
    }

    public function markSent(CustomerMessage $message, User $user): CustomerMessage
    {
        $message->update([
            'status' => CustomerMessage::SENT,
            'error' => null,
            'sent_at' => now(),
            'sent_by' => $user->id,
        ]);

        return $message;
    }

    /**
     * Shape for the staff UI: the message plus the link that opens WhatsApp with it.
     */
    public function present(CustomerMessage $message): array
    {
        return [
            'id' => $message->id,
            'template' => $message->template,
            'body' => $message->body,
            'phone' => $message->phone,
            'channel' => $message->channel,
            'status' => $message->status,
            'error' => $message->error,
            'share_url' => $this->shareUrl($message),
            'sent_at' => $message->sent_at?->toIso8601String(),
            'delivered_at' => $message->delivered_at?->toIso8601String(),
            'read_at' => $message->read_at?->toIso8601String(),
            'sent_by' => $message->relationLoaded('sentBy') ? $message->sentBy?->only(['id', 'name']) : null,
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }
}
