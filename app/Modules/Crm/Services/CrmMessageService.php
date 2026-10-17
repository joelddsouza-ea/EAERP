<?php

namespace App\Modules\Crm\Services;

use App\Core\Models\Branch;
use App\Core\Models\User;
use App\Core\Services\WhatsApp\ShareLinkChannel;
use App\Core\Services\WhatsApp\WhatsAppChannel;
use App\Core\Support\WorkshopSettings;
use App\Modules\Crm\Models\Creative;
use App\Modules\Crm\Models\CrmLink;
use App\Modules\Crm\Models\Lead;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\CustomerMessage;
use App\Modules\Workshop\Models\PackagePricing;
use App\Modules\Workshop\Models\ServicePackage;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\Vehicle;
use App\Modules\Workshop\Services\CustomerMessageService;

/**
 * CRM messages to leads and customers (quotes, journey creatives, booking confirmations,
 * feedback requests), logged with the job-card messages (purpose "crm"). With the share
 * channel they wait in "Messages to send" for a one-tap WhatsApp send; with the API
 * channel (Interakt, later) they go at once.
 *
 * Placeholders: :name :car :workshop :offer :plan :price :book_link :feedback_link :referral_link
 * :view_link :due_date and any passed in. Links are made only when the text uses them.
 */
class CrmMessageService
{
    public function __construct(protected WhatsAppChannel $channel) {}

    /**
     * @param array<string, string> $params
     * @param array{marketing?: bool, scheduled_at?: mixed, enrollment_id?: ?int, job_card_id?: ?int, vehicle_id?: ?int, package_id?: ?int} $options
     */
    public function toLead(Lead $lead, string $template, string $text, array $params = [], ?Creative $creative = null, ?User $user = null, array $options = []): ?CustomerMessage
    {
        if (($options['marketing'] ?? false) && ($lead->opted_out_at || $lead->customer?->marketing_opt_out_at)) {
            return null;
        }

        $lead->loadMissing(['vehicle.make', 'vehicle.model', 'make', 'model', 'branch.organization', 'customer']);
        $context = [
            'lead_id' => $lead->id,
            'customer_id' => $lead->customer_id,
            'vehicle_id' => $options['vehicle_id'] ?? $lead->vehicle_id,
            'package_id' => $options['package_id'] ?? $lead->package_id,
            'branch_id' => $lead->branch_id,
            'job_card_id' => $options['job_card_id'] ?? null,
        ];

        $params = [...$this->planValues($text, $context['package_id'], $lead->category_id ?? $lead->vehicle?->category_id), ...$params];

        return $this->make($lead->phone, $lead->name, $lead->carLabel(), $lead->branch, $context, $template, $text, $params, $creative, $user, [
            ...$options,
            'assigned_to' => $lead->assigned_to,
        ]);
    }

    public function toCustomer(Customer $customer, ?Vehicle $vehicle, string $template, string $text, array $params = [], ?Creative $creative = null, ?User $user = null, array $options = []): ?CustomerMessage
    {
        if (($options['marketing'] ?? false) && $customer->marketing_opt_out_at) {
            return null;
        }

        $vehicle?->loadMissing(['make', 'model']);
        $jobCard = !empty($options['job_card_id']) ? JobCard::withoutGlobalScopes()->find($options['job_card_id']) : null;
        $branch = $jobCard?->branch ?? Branch::find($options['branch_id'] ?? null) ?? Branch::active()->orderBy('id')->first();
        $car = $vehicle ? trim(trim(($vehicle->make?->name ?? '') . ' ' . ($vehicle->model?->name ?? '')) . ($vehicle->registration_no ? " ({$vehicle->registration_no})" : '')) : 'car';

        $context = [
            'lead_id' => $options['lead_id'] ?? null,
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle?->id,
            'package_id' => $options['package_id'] ?? null,
            'branch_id' => $branch?->id,
            'job_card_id' => $jobCard?->id,
        ];

        $params = [...$this->planValues($text, $context['package_id'], $vehicle?->category_id), ...$params];

        return $this->make($customer->whatsapp_phone ?: $customer->phone, $customer->name, $car ?: 'car', $branch, $context, $template, $text, $params, $creative, $user, $options);
    }

    /**
     * :plan and :price for texts that use them: the chosen plan (else the lowest-priced one)
     * at its lowest price for the car type (any type when it isn't known).
     *
     * @return array<string, string>
     */
    protected function planValues(string $text, ?int $packageId, ?int $categoryId): array
    {
        if (!str_contains($text, ':price') && !str_contains($text, ':plan')) {
            return [];
        }

        $price = PackagePricing::query()
            ->whereIn('package_id', $packageId ? [$packageId] : ServicePackage::where('is_active', true)->select('id'))
            ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
            ->where('price', '>', 0)
            ->orderBy('price')
            ->first(['package_id', 'price']);
        $plan = ServicePackage::whereKey($packageId ?? $price?->package_id)->value('name');

        return [
            'plan' => $plan ?? 'service plan',
            'price' => $price ? 'Rs ' . number_format((float) $price->price, 0) : 'a fixed, upfront price',
        ];
    }

    protected function make(?string $rawPhone, string $name, string $car, ?Branch $branch, array $context, string $template, string $text, array $params, ?Creative $creative, ?User $user, array $options): CustomerMessage
    {
        $branch?->loadMissing('organization');
        $values = [
            'name' => $name,
            'car' => $car,
            'workshop' => $branch?->organization?->name ?? config('app.name'),
            'offer' => (string) WorkshopSettings::get('crm.offer_text'),
            ...$params,
        ];

        // Links only when the text asks for them
        $links = [
            'book_link' => CrmLink::BOOK,
            'feedback_link' => CrmLink::FEEDBACK,
            'referral_link' => CrmLink::REFERRAL,
        ];
        foreach ($links as $placeholder => $purpose) {
            if (str_contains($text, ':' . $placeholder) && !isset($values[$placeholder])) {
                $values[$placeholder] = $this->link($purpose, $context)->url();
            }
        }

        $body = trim(CustomerMessageService::fill($text, $values));

        // A file can't ride on a share link: the message carries a link to view it
        $mediaUrl = null;
        if ($creative?->hasFile()) {
            $view = $this->link(CrmLink::CREATIVE, [...$context, 'creative_id' => $creative->id]);
            $mediaUrl = url('/api/v1/public/c/' . $view->token() . '/media');
            if (str_contains($body, ':view_link')) {
                $body = str_replace(':view_link', $view->url(), $body);
            } else {
                $body .= "\n" . $view->url();
            }
        }

        $phone = ShareLinkChannel::normalizePhone($rawPhone);
        $message = new CustomerMessage([
            'job_card_id' => $context['job_card_id'],
            'customer_id' => $context['customer_id'],
            'lead_id' => $context['lead_id'],
            'purpose' => 'crm',
            'creative_id' => $creative?->id,
            'journey_enrollment_id' => $options['enrollment_id'] ?? null,
            'template' => substr($template, 0, 50),
            'body' => $body,
            'media_url' => $mediaUrl,
            'phone' => $phone,
            'channel' => $this->channel->name(),
            'scheduled_at' => $options['scheduled_at'] ?? now(),
            'assigned_to' => $options['assigned_to'] ?? null,
            'prepared_by' => $user?->id,
        ]);

        if (!$phone) {
            $message->status = CustomerMessage::FAILED;
            $message->error = 'No valid mobile number for WhatsApp.';
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
     * The open link of this kind for the lead / customer (re-used so the same link repeats).
     */
    public function link(string $purpose, array $context): CrmLink
    {
        $query = CrmLink::where('purpose', $purpose)->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()->addDays(3)));
        foreach (['lead_id', 'customer_id', 'vehicle_id', 'job_card_id', 'creative_id'] as $key) {
            if (in_array($purpose, [CrmLink::REFERRAL], true) && $key !== 'customer_id') {
                continue;
            }
            $value = $context[$key] ?? null;
            $value === null ? $query->whereNull($key) : $query->where($key, $value);
        }

        $existing = $query->latest('id')->first();
        if ($existing) {
            return $existing;
        }

        $days = (int) WorkshopSettings::get('crm.link_valid_days');

        // A customer's referral code (also usable as ?ref= on the website form)
        if ($purpose === CrmLink::REFERRAL && !empty($context['customer_id'])) {
            $customer = Customer::find($context['customer_id']);
            if ($customer && !$customer->referral_code) {
                do {
                    $code = strtoupper(\Illuminate\Support\Str::random(6));
                } while (Customer::where('referral_code', $code)->exists());
                $customer->update(['referral_code' => $code]);
            }
        }

        return CrmLink::issue([
            'purpose' => $purpose,
            'lead_id' => $context['lead_id'] ?? null,
            'customer_id' => $context['customer_id'] ?? null,
            'vehicle_id' => $context['vehicle_id'] ?? null,
            'job_card_id' => $context['job_card_id'] ?? null,
            'creative_id' => $context['creative_id'] ?? null,
            'package_id' => $context['package_id'] ?? null,
            'branch_id' => $context['branch_id'] ?? null,
            // A referral link is the customer's for good
            'expires_at' => $purpose === CrmLink::REFERRAL ? null : now()->addDays(max(7, $days)),
        ]);
    }

    public function markSent(CustomerMessage $message, User $user): CustomerMessage
    {
        $message->update(['status' => CustomerMessage::SENT, 'sent_at' => now(), 'sent_by' => $user->id]);
        if ($message->lead_id) {
            app(LeadService::class)->log($message->lead_id, 'whatsapp', null, mb_strimwidth($message->body, 0, 400, '…'), $user);
        }

        return $message;
    }

    public function shareUrl(CustomerMessage $message): ?string
    {
        return $message->phone ? $this->channel->shareUrl($message->phone, $message->body) : null;
    }

    public function present(CustomerMessage $m): array
    {
        $m->loadMissing(['lead:id,name,phone,status,temperature', 'customer:id,name,phone', 'assignee:id,name']);

        return [
            'id' => $m->id,
            'template' => $m->template,
            'body' => $m->body,
            'phone' => $m->phone,
            'status' => $m->status,
            'error' => $m->error,
            'scheduled_at' => $m->scheduled_at?->toIso8601String(),
            'sent_at' => $m->sent_at?->toIso8601String(),
            'share_url' => $m->status === CustomerMessage::PREPARED ? $this->shareUrl($m) : null,
            'lead' => $m->lead ? ['id' => $m->lead->id, 'name' => $m->lead->name, 'status' => $m->lead->status, 'temperature' => $m->lead->temperature] : null,
            'customer' => $m->customer ? ['id' => $m->customer->id, 'name' => $m->customer->name] : null,
            'assignee' => $m->assignee?->name,
            'has_media' => (bool) $m->media_url,
        ];
    }
}
