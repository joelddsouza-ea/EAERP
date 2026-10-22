<?php

namespace App\Modules\Crm\Services\WhatsApp;

use App\Core\Services\WhatsApp\InteraktChannel;
use App\Core\Services\WhatsApp\ShareLinkChannel;
use App\Core\Services\WhatsApp\WhatsAppSettings;
use App\Core\Support\Modules;
use App\Core\Support\WorkshopSettings;
use App\Modules\Crm\Models\InteraktOutbox as Outbox;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\RetentionService;
use App\Modules\Workshop\Enums\JobCardStatus;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\Vehicle;

/**
 * Keeps Interakt's contacts in step with the CRM, so Interakt's own campaigns, segments and
 * workflows can use our data:
 *  - contacts (Track Users API): leads and customers with traits (car, plan, status, next
 *    service …) and tags (Lead / Customer / Fleet, source, branch). Interakt keeps the
 *    latest value of a trait; tags can only be added.
 *  - events (Track Events API): LeadCreated, LeadStatusChanged, LeadLost, BookingConfirmed,
 *    JobDelivered, ServiceDue, InsuranceDue — an Interakt "ongoing campaign" can be started by
 *    any of them.
 * Changes wait in interakt_outbox; crm:run sends them (at most 250 a run, within Interakt's
 * 300 a minute), a 429 or an outage is tried again later. Nothing goes while Interakt or the
 * switches in CRM › WhatsApp › Contacts & events are off.
 */
class InteraktSyncService
{
    public const BATCH = 250;
    public const MAX_ATTEMPTS = 8;
    public const EVENTS = ['LeadCreated', 'LeadStatusChanged', 'LeadLost', 'BookingConfirmed', 'JobDelivered', 'ServiceDue', 'InsuranceDue'];

    public function __construct(protected InteraktChannel $interakt) {}

    public static function contactsOn(): bool
    {
        return Modules::crm() && WhatsAppSettings::apiOn() && (bool) WorkshopSettings::get('crm.whatsapp.sync_contacts');
    }

    public static function eventsOn(): bool
    {
        return Modules::crm() && WhatsAppSettings::apiOn() && (bool) WorkshopSettings::get('crm.whatsapp.sync_events');
    }

    // ------------------------------------------------------------------ queueing

    /**
     * The contact is sent as it is when the outbox is drained: one waiting row per contact.
     */
    public function queueContact(string $type, int $id): void
    {
        if (!self::contactsOn()) {
            return;
        }
        $subject = "{$type}:{$id}";
        if (Outbox::where('subject', $subject)->where('kind', Outbox::USER)->whereNull('sent_at')->exists()) {
            return;
        }
        Outbox::create(['kind' => Outbox::USER, 'subject' => $subject, 'payload' => ['type' => $type, 'id' => $id], 'next_try_at' => now()]);
    }

    public function queueEvent(string $phone, string $event, array $traits = [], ?string $dedupe = null): void
    {
        $phone = ShareLinkChannel::normalizePhone($phone);
        if (!$phone || !self::eventsOn()) {
            return;
        }
        $subject = mb_substr($dedupe ?? "phone:{$phone}", 0, 40);
        if ($dedupe && Outbox::where('subject', $subject)->where('event', $event)->exists()) {
            return;
        }
        Outbox::create([
            'kind' => Outbox::EVENT,
            'subject' => $subject,
            'event' => $event,
            'payload' => ['phone' => $phone, 'traits' => self::cleanTraits($traits)],
            'next_try_at' => now(),
        ]);
    }

    /**
     * Everyone once (CRM › WhatsApp › Contacts & events › Send all contacts, or
     * crm:interakt-sync --all): open and recent leads, active customers.
     */
    public function queueAll(): int
    {
        if (!self::contactsOn()) {
            return 0;
        }
        $n = 0;
        Lead::where(fn ($q) => $q->whereIn('status', Lead::OPEN)->orWhere('updated_at', '>', now()->subMonths(6)))
            ->select('id')->lazyById()->each(function ($l) use (&$n) {
                $this->queueContact('lead', $l->id);
                $n++;
            });
        Customer::where('is_active', true)->select('id')->lazyById()->each(function ($c) use (&$n) {
            $this->queueContact('customer', $c->id);
            $n++;
        });

        return $n;
    }

    /**
     * Daily: ServiceDue (in 7 days) and InsuranceDue (in 15 days) events, once per due date.
     */
    public function queueDueEvents(): int
    {
        if (!self::eventsOn()) {
            return 0;
        }
        $n = 0;
        $retention = app(RetentionService::class);
        Vehicle::with('customer:id,phone,whatsapp_phone,name,is_active')->whereNotNull('customer_id')->where('is_active', true)
            ->lazyById()->each(function (Vehicle $v) use ($retention, &$n) {
                if (!$v->customer?->is_active) {
                    return;
                }
                $phone = $v->customer->whatsapp_phone ?: $v->customer->phone;
                $due = $retention->serviceDueDate($v);
                if ($due && $due->isSameDay(today()->addDays(7))) {
                    $this->queueEvent($phone, 'ServiceDue', ['registrationNo' => $v->registration_no, 'dueDate' => $due->toDateString()], "sd:{$v->id}:{$due->toDateString()}");
                    $n++;
                }
                if ($v->insurance_expiry && $v->insurance_expiry->isSameDay(today()->addDays(15))) {
                    $this->queueEvent($phone, 'InsuranceDue', ['registrationNo' => $v->registration_no, 'expiryDate' => $v->insurance_expiry->toDateString()], "ins:{$v->id}:{$v->insurance_expiry->toDateString()}");
                    $n++;
                }
            });

        return $n;
    }

    // ------------------------------------------------------------------ sending

    /**
     * @return array{sent: int, failed: int, waiting: int}
     */
    public function drain(int $limit = self::BATCH): array
    {
        $sent = 0;
        $failed = 0;
        if (!WhatsAppSettings::apiOn()) {
            return ['sent' => 0, 'failed' => 0, 'waiting' => $this->waiting()];
        }

        $rows = Outbox::whereNull('sent_at')->where('attempts', '<', self::MAX_ATTEMPTS)
            ->where(fn ($q) => $q->whereNull('next_try_at')->orWhere('next_try_at', '<=', now()))
            ->orderBy('id')->limit($limit)->get();

        foreach ($rows as $row) {
            $result = $row->kind === Outbox::USER ? $this->sendContact($row) : $this->sendEvent($row);
            if ($result === null) {
                // Nothing to send (no valid number, deleted): done
                $row->update(['sent_at' => now(), 'error' => 'Skipped: no valid mobile number.']);
                continue;
            }
            if ($result['ok']) {
                $row->update(['sent_at' => now(), 'error' => null, 'attempts' => $row->attempts + 1]);
                $sent++;
                continue;
            }
            $failed++;
            $row->update([
                'attempts' => $row->attempts + 1,
                'error' => mb_substr((string) $result['error'], 0, 500),
                'next_try_at' => now()->addSeconds($result['retry_after'] ?? (60 * 2 ** min($row->attempts, 6))),
            ]);
            // Over the rate limit: stop this run, the rest go next time
            if (!empty($result['retry_after'])) {
                break;
            }
        }

        return ['sent' => $sent, 'failed' => $failed, 'waiting' => $this->waiting()];
    }

    public function waiting(): int
    {
        return Outbox::whereNull('sent_at')->where('attempts', '<', self::MAX_ATTEMPTS)->count();
    }

    public function status(): array
    {
        return [
            'waiting' => $this->waiting(),
            'sent_today' => Outbox::whereNotNull('sent_at')->whereNull('error')->where('sent_at', '>=', today())->count(),
            'gave_up' => Outbox::whereNull('sent_at')->where('attempts', '>=', self::MAX_ATTEMPTS)->count(),
            'errors' => Outbox::whereNotNull('error')->whereNull('sent_at')->latest('id')->limit(10)->get(['id', 'kind', 'subject', 'event', 'error', 'attempts', 'updated_at'])
                ->map(fn ($r) => ['subject' => $r->subject, 'what' => $r->event ?? 'contact', 'error' => $r->error, 'attempts' => $r->attempts, 'at' => $r->updated_at?->toIso8601String()])->values(),
        ];
    }

    protected function sendContact(Outbox $row): ?array
    {
        $type = $row->payload['type'] ?? null;
        $id = (int) ($row->payload['id'] ?? 0);
        [$phone, $traits, $tags] = match ($type) {
            'lead' => $this->leadContact($id),
            'customer' => $this->customerContact($id),
            default => [null, [], []],
        };
        if (!$phone) {
            return null;
        }
        $result = $this->interakt->trackUser($phone, self::cleanTraits($traits), $tags);
        if ($result['ok']) {
            ($type === 'lead' ? Lead::class : Customer::class)::whereKey($id)->update(['interakt_synced_at' => now()]);
        }

        return $result;
    }

    protected function sendEvent(Outbox $row): ?array
    {
        $phone = $row->payload['phone'] ?? null;

        return $phone ? $this->interakt->trackEvent($phone, (string) $row->event, $row->payload['traits'] ?? []) : null;
    }

    /**
     * @return array{0: ?string, 1: array, 2: array<int, string>}
     */
    protected function leadContact(int $id): array
    {
        $lead = Lead::with(['source:id,name', 'branch:id,name', 'package:id,name', 'assignee:id,name'])->find($id);
        $phone = $lead ? ShareLinkChannel::normalizePhone($lead->phone) : null;
        if (!$phone) {
            return [null, [], []];
        }
        // A customer's own contact carries their details; the lead adds what it knows
        return [$phone, [
            'name' => $lead->name,
            'email' => $lead->email,
            'city' => $lead->city,
            'car' => $lead->carLabel(),
            'interest' => Lead::INTERESTS[$lead->interest] ?? $lead->interest,
            'plan' => $lead->package?->name,
            'branch' => $lead->branch?->name,
            'leadStatus' => Lead::STATUSES[$lead->status] ?? $lead->status,
            'leadTemperature' => $lead->temperature,
            'leadSource' => $lead->source?->name,
            'leadOwner' => $lead->assignee?->name,
            'leadCreatedAt' => $lead->created_at?->utc()->toIso8601String(),
            'marketingOptOut' => $lead->opted_out_at !== null,
        ], array_values(array_filter(['Lead', $lead->source?->name, $lead->branch?->name]))];
    }

    /**
     * @return array{0: ?string, 1: array, 2: array<int, string>}
     */
    protected function customerContact(int $id): array
    {
        $customer = Customer::with(['vehicles' => fn ($q) => $q->where('is_active', true)->select('id', 'customer_id', 'registration_no', 'make_id', 'model_id', 'next_service_due_date', 'next_service_due_km', 'last_service_at', 'last_service_km', 'insurance_expiry'), 'vehicles.make:id,name', 'vehicles.model:id,name'])->find($id);
        $phone = $customer ? ShareLinkChannel::normalizePhone($customer->whatsapp_phone ?: $customer->phone) : null;
        if (!$phone) {
            return [null, [], []];
        }
        $retention = app(RetentionService::class);
        $nextDue = $customer->vehicles->map(fn (Vehicle $v) => $retention->serviceDueDate($v))->filter()->sort()->first();
        $jobs = JobCard::withoutGlobalScopes()->where('customer_id', $customer->id)->where('status', JobCardStatus::Delivered->value);
        $lastVisit = (clone $jobs)->max('status_changed_at');

        return [$phone, [
            'name' => $customer->name,
            'email' => $customer->email,
            'city' => $customer->city,
            'customerType' => $customer->customer_type,
            'company' => $customer->company_name,
            'fleet' => (bool) $customer->is_fleet,
            'cars' => $customer->vehicles->map(fn (Vehicle $v) => trim($v->registration_no . ' ' . ($v->make?->name ?? '') . ' ' . ($v->model?->name ?? '')))->take(10)->values()->all(),
            'visits' => (clone $jobs)->count(),
            'lastVisit' => $lastVisit ? \Illuminate\Support\Carbon::parse($lastVisit)->utc()->toIso8601String() : null,
            'nextServiceDue' => $nextDue?->copy()->utc()->toIso8601String(),
            'customerSince' => $customer->created_at?->utc()->toIso8601String(),
            'marketingOptOut' => $customer->marketing_opt_out_at !== null,
        ], array_values(array_filter(['Customer', $customer->is_fleet ? 'Fleet' : null, $customer->customer_type === 'corporate' ? 'Corporate' : null]))];
    }

    /**
     * Interakt refuses traits with new lines, tabs or more than two spaces in a row, and
     * requests over 32 KB.
     */
    public static function cleanTraits(array $traits): array
    {
        $out = [];
        foreach ($traits as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $out[$key] = match (true) {
                is_bool($value), is_int($value), is_float($value) => $value,
                is_array($value) => array_values(array_map(fn ($v) => self::cleanText((string) $v, 200), array_filter($value, 'is_scalar'))),
                default => self::cleanText((string) $value, 500),
            };
        }
        while (strlen(json_encode($out)) > 30000 && $out) {
            array_pop($out);
        }

        return $out;
    }

    public static function cleanText(string $text, int $max): string
    {
        return mb_substr(trim(preg_replace(['/[\r\n\t]+/', '/ {3,}/'], [' ', '  '], $text)), 0, $max);
    }
}
