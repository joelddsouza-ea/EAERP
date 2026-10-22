<?php

namespace App\Modules\Crm\Console;

use App\Core\Models\Role;
use App\Core\Models\User;
use App\Core\Support\WorkshopSettings;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\BookingService;
use App\Modules\Crm\Services\JourneyService;
use App\Modules\Workshop\Notifications\StaffAlert;
use Illuminate\Console\Command;

/**
 * Every 15 minutes: journey steps that are due, first-contact SLA alerts, no-shows,
 * (in the evening) tomorrow's booking reminders, and contacts / events waiting for Interakt.
 */
class RunCrm extends Command
{
    protected $signature = 'crm:run';

    protected $description = 'Run due journey steps, SLA alerts, no-shows and booking reminders';

    public function handle(JourneyService $journeys, BookingService $bookings, \App\Modules\Crm\Services\WhatsApp\InteraktSyncService $interakt): int
    {
        if (!\App\Core\Support\Modules::crm()) {
            $this->info('CRM is switched off.');

            return self::SUCCESS;
        }

        $sent = $journeys->run();
        $alerts = $this->slaAlerts();
        $noShows = $bookings->markNoShows();
        $reminders = now()->hour >= 17 ? $bookings->sendReminders() : 0;

        $synced = $interakt->drain();

        $this->info("journey steps: {$sent}, SLA alerts: {$alerts}, no-shows: {$noShows}, reminders: {$reminders}, interakt sync: {$synced['sent']} sent / {$synced['waiting']} waiting");

        return self::SUCCESS;
    }

    /**
     * A new lead not called by its due time: the executive is reminded, then (at twice
     * the SLA) the managers are told.
     */
    protected function slaAlerts(): int
    {
        $count = 0;
        $sla = max(1, (int) WorkshopSettings::get('crm.sla_minutes'));

        Lead::with('assignee')
            ->where('status', Lead::NEW)
            ->whereNull('first_contacted_at')
            ->where('first_contact_due_at', '<', now())
            ->where(fn ($q) => $q->whereNull('sla_alerted_at')->orWhereNull('sla_escalated_at'))
            ->limit(200)
            ->get()
            ->each(function (Lead $lead) use (&$count, $sla) {
                if (!$lead->sla_alerted_at) {
                    $lead->assignee?->notify(new StaffAlert('lead_sla', "Call now — {$lead->name}", "New lead waiting since {$lead->created_at->format('H:i')}.", "/crm/leads/{$lead->id}"));
                    $lead->update(['sla_alerted_at' => now()]);
                    $count++;

                    return;
                }
                if ($lead->first_contact_due_at->copy()->addMinutes($sla)->isPast()) {
                    $alert = new StaffAlert('lead_sla_escalated', "Lead not called — {$lead->name}", ($lead->assignee?->name ?? 'Nobody') . " has not called this lead yet (since {$lead->created_at->format('d M H:i')}).", "/crm/leads/{$lead->id}");
                    User::active()->whereHas('roles', fn ($q) => $q->whereIn('slug', [Role::MANAGER, Role::OWNER]))
                        ->when($lead->branch_id, fn ($q) => $q->where(fn ($w) => $w->whereHas('roles', fn ($r) => $r->where('slug', Role::OWNER))->orWhereHas('branches', fn ($b) => $b->where('branches.id', $lead->branch_id))))
                        ->get()->each->notify($alert);
                    $lead->update(['sla_escalated_at' => now()]);
                    $count++;
                }
            });

        return $count;
    }
}
