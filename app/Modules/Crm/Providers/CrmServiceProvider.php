<?php

namespace App\Modules\Crm\Providers;

use App\Modules\Crm\Console\CrmDaily;
use App\Modules\Crm\Console\InteraktSync;
use App\Modules\Crm\Console\RunCrm;
use App\Modules\Crm\Models\Booking;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\WhatsApp\InteraktSyncService;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Crm\Services\RetentionService;
use App\Modules\Workshop\Models\JobCardStatusHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class CrmServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware('api')
            ->prefix('api')
            ->group(__DIR__ . '/../Routes/api.php');

        if ($this->app->runningInConsole()) {
            $this->commands([RunCrm::class, CrmDaily::class, InteraktSync::class]);
        }

        $this->interaktSync();

        // A delivered job wins its lead, starts the thank-you / feedback journey, pending
        // work and referral rewards — once the change is committed
        JobCardStatusHistory::created(function (JobCardStatusHistory $h) {
            $jobCardId = (int) $h->job_card_id;
            $status = (string) $h->to_status;
            DB::afterCommit(function () use ($jobCardId, $status) {
                if (!\App\Core\Support\Modules::crm()) {
                    return;
                }
                try {
                    app(RetentionService::class)->onJobStatus($jobCardId, $status);
                } catch (\Throwable $e) {
                    if (app()->runningUnitTests()) {
                        throw $e;
                    }
                    report($e);
                }
            });
        });
    }

    /**
     * Contacts and events for Interakt (queued after the change is saved; sent by crm:run).
     * Nothing is queued while Interakt or the switches are off.
     */
    protected function interaktSync(): void
    {
        $sync = fn () => app(InteraktSyncService::class);
        $quietly = function (callable $fn) {
            DB::afterCommit(function () use ($fn) {
                try {
                    $fn();
                } catch (\Throwable $e) {
                    if (app()->runningUnitTests()) {
                        throw $e;
                    }
                    report($e);
                }
            });
        };

        Lead::created(function (Lead $lead) use ($sync, $quietly) {
            $id = $lead->id;
            $quietly(function () use ($sync, $id) {
                $lead = Lead::with('source:id,name')->find($id);
                if (!$lead) {
                    return;
                }
                $sync()->queueContact('lead', $lead->id);
                $sync()->queueEvent($lead->phone, 'LeadCreated', ['source' => $lead->source?->name, 'car' => $lead->carLabel(), 'requirement' => $lead->requirement], "lead:{$lead->id}");
            });
        });
        Lead::updated(function (Lead $lead) use ($sync, $quietly) {
            $relevant = ['name', 'email', 'city', 'status', 'temperature', 'package_id', 'interest', 'car_text', 'assigned_to', 'opted_out_at', 'branch_id'];
            if (!$lead->wasChanged($relevant)) {
                return;
            }
            $id = $lead->id;
            $from = $lead->getOriginal('status');
            $to = $lead->status;
            $statusChanged = $lead->wasChanged('status');
            $quietly(function () use ($sync, $id, $from, $to, $statusChanged) {
                $lead = Lead::with('lostReason:id,name')->find($id);
                if (!$lead) {
                    return;
                }
                $sync()->queueContact('lead', $id);
                if ($statusChanged) {
                    $sync()->queueEvent($lead->phone, 'LeadStatusChanged', ['from' => Lead::STATUSES[$from] ?? $from, 'to' => Lead::STATUSES[$to] ?? $to]);
                    if ($to === Lead::LOST) {
                        $sync()->queueEvent($lead->phone, 'LeadLost', ['reason' => $lead->lostReason?->name, 'note' => $lead->lost_note]);
                    }
                }
            });
        });
        Customer::saved(function (Customer $customer) use ($sync, $quietly) {
            if (!$customer->wasRecentlyCreated && !$customer->wasChanged(['name', 'email', 'city', 'phone', 'whatsapp_phone', 'customer_type', 'company_name', 'is_fleet', 'marketing_opt_out_at', 'is_active'])) {
                return;
            }
            $id = $customer->id;
            $quietly(fn () => $sync()->queueContact('customer', $id));
        });
        Booking::updated(function (Booking $booking) use ($sync, $quietly) {
            if (!$booking->wasChanged('status') || $booking->status !== Booking::CONFIRMED) {
                return;
            }
            $id = $booking->id;
            $quietly(function () use ($sync, $id) {
                $b = Booking::with(['lead:id,phone', 'customer:id,phone,whatsapp_phone', 'vehicle:id,registration_no'])->find($id);
                $phone = $b?->customer ? ($b->customer->whatsapp_phone ?: $b->customer->phone) : $b?->lead?->phone;
                if ($phone) {
                    $sync()->queueEvent($phone, 'BookingConfirmed', [
                        'bookingNo' => $b->number,
                        'date' => $b->booking_date?->toDateString(),
                        'slot' => $b->slot_from ? substr($b->slot_from, 0, 5) : null,
                        'mode' => $b->service_mode,
                        'registrationNo' => $b->vehicle?->registration_no,
                    ], "bk:{$b->id}");
                }
            });
        });
        \App\Modules\Workshop\Models\JobCardStatusHistory::created(function ($h) use ($sync, $quietly) {
            if ($h->to_status !== 'delivered') {
                return;
            }
            $jobId = (int) $h->job_card_id;
            $quietly(function () use ($sync, $jobId) {
                $job = JobCard::withoutGlobalScopes()->with(['customer:id,phone,whatsapp_phone', 'vehicle:id,registration_no', 'package:id,name'])->find($jobId);
                if (!$job?->customer) {
                    return;
                }
                $sync()->queueEvent($job->customer->whatsapp_phone ?: $job->customer->phone, 'JobDelivered', [
                    'jobNo' => $job->job_number,
                    'registrationNo' => $job->vehicle?->registration_no,
                    'plan' => $job->package?->name,
                    'amount' => (float) (app(\App\Modules\Billing\Services\BillingService::class)->summary($job)['bill_total'] ?? 0),
                ], "job:{$job->id}");
                $sync()->queueContact('customer', $job->customer_id);
            });
        });
    }
}
