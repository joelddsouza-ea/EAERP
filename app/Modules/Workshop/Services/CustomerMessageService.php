<?php

namespace App\Modules\Workshop\Services;

use App\Core\Models\User;
use App\Core\Services\WhatsApp\ShareLinkChannel;
use App\Core\Services\WhatsApp\WhatsAppChannel;
use App\Modules\Workshop\Models\CustomerMessage;
use App\Modules\Workshop\Models\JobCard;

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
        $jobCard->loadMissing(['customer', 'vehicle', 'branch.organization']);
        $customer = $jobCard->customer;

        $body = __("customer_messages.{$template}", [
            'name' => $customer->name,
            'car' => $jobCard->vehicle?->registration_no ?? '',
            'job' => $jobCard->job_number,
            'workshop' => $jobCard->branch?->organization?->name ?? config('app.name'),
            'link' => $this->links->ensure($jobCard, $user)->url(),
            ...$params,
        ]);

        $phone = ShareLinkChannel::normalizePhone($customer->whatsapp_phone ?: $customer->phone);

        $message = new CustomerMessage([
            'job_card_id' => $jobCard->id,
            'customer_id' => $customer->id,
            'template' => $template,
            'body' => $body,
            'phone' => $phone,
            'channel' => $this->channel->name(),
            'prepared_by' => $user?->id,
        ]);

        if (!$phone) {
            $message->status = CustomerMessage::FAILED;
            $message->error = 'The customer has no valid mobile number for WhatsApp.';
        } elseif ($this->channel->needsManualSend()) {
            $message->status = CustomerMessage::PREPARED;
        } else {
            $error = $this->channel->deliver($phone, $body);
            $message->status = $error ? CustomerMessage::FAILED : CustomerMessage::SENT;
            $message->error = $error;
            $message->sent_at = $error ? null : now();
        }

        $message->save();

        return $message;
    }

    /**
     * Message for a status the customer is told about (config customer_channel.status_templates).
     */
    public function prepareForStatus(JobCard $jobCard, string $status, ?User $user = null): ?CustomerMessage
    {
        $template = config("workshop.customer_channel.status_templates.{$status}");

        return $template ? $this->prepare($jobCard, $template, [], $user) : null;
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
            'sent_by' => $message->relationLoaded('sentBy') ? $message->sentBy?->only(['id', 'name']) : null,
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }
}
