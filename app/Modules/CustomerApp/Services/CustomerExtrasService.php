<?php

namespace App\Modules\CustomerApp\Services;

use App\Core\Services\FileStorageService;
use App\Core\Support\Modules;
use App\Core\Support\WorkshopSettings;
use App\Modules\Billing\Models\CustomerCredit;
use App\Modules\Crm\Models\CrmLink;
use App\Modules\Crm\Services\CrmMessageService;
use App\Modules\Crm\Services\RetentionService;
use App\Modules\CustomerApp\Models\CustomerDocument;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\JobCardObservation;
use App\Modules\Workshop\Models\Vehicle;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The customer app's wallet (credit, referral), document locker, and the daily reminders
 * (service due, insurance / PUC / document expiry, work recommended earlier).
 */
class CustomerExtrasService
{
    public function __construct(
        protected FileStorageService $files,
        protected CustomerNotifier $notifier,
    ) {}

    // ------------------------------------------------------------------ wallet

    public function wallet(Customer $customer): array
    {
        $entries = CustomerCredit::where('customer_id', $customer->id)->latest('id')->limit(50)->get();
        $referral = null;
        if (Modules::crm()) {
            $link = app(CrmMessageService::class)->link(CrmLink::REFERRAL, ['customer_id' => $customer->id]);
            $referral = [
                'code' => $customer->fresh()->referral_code,
                'url' => $link->url(),
                'reward' => (float) WorkshopSettings::get('crm.referral_reward'),
            ];
        }

        return [
            'balance' => CustomerCredit::balanceFor($customer->id),
            'entries' => $entries->map(fn (CustomerCredit $c) => [
                'amount' => (float) $c->amount,
                'note' => $c->note,
                'date' => $c->created_at?->toDateString(),
            ])->values(),
            'referral' => $referral,
            'offer' => Modules::crm() ? (WorkshopSettings::get('crm.offer_text') ?: null) : null,
        ];
    }

    // ------------------------------------------------------------------ documents

    /**
     * @param array<int>|null $vehicleIds a fleet driver's cabs (null = all the customer's)
     */
    public function documents(Customer $customer, ?array $vehicleIds = null): array
    {
        return CustomerDocument::with('vehicle:id,registration_no')->where('customer_id', $customer->id)
            ->when($vehicleIds !== null, fn ($q) => $q->whereIn('vehicle_id', $vehicleIds ?: [0]))->latest('id')->get()
            ->map(fn (CustomerDocument $d) => $this->presentDocument($d))->values()->all();
    }

    public function addDocument(Customer $customer, array $data, UploadedFile $file): CustomerDocument
    {
        $vehicle = !empty($data['vehicle_id']) ? Vehicle::where('customer_id', $customer->id)->findOrFail($data['vehicle_id']) : null;
        $stored = $this->files->uploadPrivateMedia($file, "customer-documents/{$customer->id}");

        $doc = CustomerDocument::create([
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle?->id,
            'type' => $data['type'],
            'title' => $data['title'] ?? null,
            'number' => $data['number'] ?? null,
            'expiry_date' => $data['expiry_date'] ?? null,
            'file_disk' => $stored['disk'],
            'file_path' => $stored['path'],
            'mime' => $file->getMimeType(),
        ]);

        // The car's own dates follow the latest document
        if ($vehicle && $doc->expiry_date) {
            if ($doc->type === 'insurance') {
                $vehicle->update(['insurance_expiry' => $doc->expiry_date, 'insurance_policy_no' => $doc->number ?: $vehicle->insurance_policy_no]);
            } elseif ($doc->type === 'puc') {
                $vehicle->update(['puc_expiry' => $doc->expiry_date]);
            }
        }

        return $doc;
    }

    public function deleteDocument(Customer $customer, int $id): void
    {
        $doc = CustomerDocument::where('customer_id', $customer->id)->findOrFail($id);
        Storage::disk($doc->file_disk)->delete($doc->file_path);
        $doc->delete();
    }

    public function presentDocument(CustomerDocument $d): array
    {
        return [
            'id' => $d->id,
            'type' => $d->type,
            'type_label' => CustomerDocument::TYPES[$d->type] ?? $d->type,
            'title' => $d->title,
            'number' => $d->number,
            'expiry_date' => $d->expiry_date?->toDateString(),
            'expired' => $d->expiry_date?->isPast() ?? false,
            'vehicle' => $d->vehicle?->registration_no,
            'vehicle_id' => $d->vehicle_id,
            'mime' => $d->mime,
            'url' => $d->fileUrl(),
        ];
    }

    // ------------------------------------------------------------------ reminders

    /**
     * Daily: inbox reminders (and phone notifications). Each is written once (dedupe key).
     *
     * @return array<string, int>
     */
    public function dailyReminders(): array
    {
        $counts = ['service_due' => 0, 'insurance_due' => 0, 'puc_due' => 0, 'document_due' => 0, 'pending_work' => 0];
        $retention = app(RetentionService::class);
        $today = today();

        // Service due in 21 / 7 / 0 days
        Vehicle::whereNotNull('customer_id')->where('is_active', true)
            ->where(fn ($q) => $q->whereNotNull('next_service_due_date')->orWhereNotNull('next_service_due_km'))
            ->lazyById()
            ->each(function (Vehicle $v) use ($retention, $today, &$counts) {
                $due = $retention->serviceDueDate($v);
                if (!$due) {
                    return;
                }
                $days = (int) $today->diffInDays($due, false);
                $step = match (true) {
                    $days === 21 || ($days < 21 && $days > 7) => 21,
                    $days <= 7 && $days > 0 => 7,
                    $days <= 0 && $days > -30 => 0,
                    default => null,
                };
                if ($step === null) {
                    return;
                }
                $title = $step === 0 ? "Service due now — {$v->registration_no}" : "Service due on {$due->format('d M')} — {$v->registration_no}";
                if ($this->notifier->notify($v->customer_id, 'service_due', $title, 'Book in a few taps: pickup & drop or at your home.', '/app/book?vehicle=' . $v->id, "service_due:{$v->id}:{$due->toDateString()}:{$step}")) {
                    $counts['service_due']++;
                }
            });

        // Insurance and PUC expiring within 30 / 7 days
        foreach (['insurance_expiry' => ['insurance_due', 'Insurance'], 'puc_expiry' => ['puc_due', 'PUC']] as $column => [$kind, $label]) {
            Vehicle::whereNotNull('customer_id')->whereBetween($column, [$today->toDateString(), $today->copy()->addDays(30)->toDateString()])
                ->lazyById()
                ->each(function (Vehicle $v) use ($column, $kind, $label, $today, &$counts) {
                    $date = $v->{$column};
                    $step = $today->diffInDays($date, false) <= 7 ? 7 : 30;
                    if ($this->notifier->notify($v->customer_id, $kind, "{$label} expires on {$date->format('d M')} — {$v->registration_no}", $kind === 'insurance_due' ? 'Renew on time to keep your no-claim bonus. Ask us for a quote.' : 'Get the PUC renewed; we can do it during your service.', '/app/cars/' . $v->id, "{$kind}:{$v->id}:{$date->toDateString()}:{$step}")) {
                        $counts[$kind]++;
                    }
                });
        }

        // Documents in the locker expiring within 30 / 7 days
        CustomerDocument::whereBetween('expiry_date', [$today->toDateString(), $today->copy()->addDays(30)->toDateString()])
            ->lazyById()
            ->each(function (CustomerDocument $d) use ($today, &$counts) {
                $step = $today->diffInDays($d->expiry_date, false) <= 7 ? 7 : 30;
                $label = CustomerDocument::TYPES[$d->type] ?? 'Document';
                if ($this->notifier->notify($d->customer_id, 'document_due', "{$label} expires on {$d->expiry_date->format('d M')}", $d->title, '/app/documents', "document_due:{$d->id}:{$d->expiry_date->toDateString()}:{$step}")) {
                    $counts['document_due']++;
                }
            });

        // Work recommended 30 / 90 days ago and not done since
        foreach ([30, 90] as $age) {
            JobCardObservation::with('jobCard:id,customer_id,vehicle_id,job_number')
                ->where('customer_visible', true)
                ->whereDate('created_at', $today->copy()->subDays($age)->toDateString())
                ->get()
                ->groupBy(fn ($o) => $o->jobCard?->customer_id . ':' . $o->jobCard?->vehicle_id)
                ->each(function ($group) use ($age, &$counts) {
                    $job = $group->first()->jobCard;
                    if (!$job?->customer_id) {
                        return;
                    }
                    $items = $group->pluck('text')->filter()->take(3)->implode(', ');
                    if ($this->notifier->notify($job->customer_id, 'pending_work', 'Recommended on your last visit', "{$items}. Book it before it costs more.", '/app/book?vehicle=' . $job->vehicle_id, "pending_work:{$job->id}:{$age}")) {
                        $counts['pending_work']++;
                    }
                });
        }

        return $counts;
    }
}
