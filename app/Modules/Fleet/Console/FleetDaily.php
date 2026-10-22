<?php

namespace App\Modules\Fleet\Console;

use App\Core\Support\Modules;
use App\Core\Support\WorkshopSettings;
use App\Modules\CustomerApp\Services\CustomerNotifier;
use App\Modules\Fleet\Models\FleetDriver;
use App\Modules\Fleet\Models\OdometerReading;
use App\Modules\Workshop\Models\Vehicle;
use Illuminate\Console\Command;

/**
 * Daily: fleet cabs close to (or past) their next service by km — the owner and the cab's
 * drivers get a note in the app, once per due km and step.
 */
class FleetDaily extends Command
{
    protected $signature = 'fleet:daily';

    protected $description = 'Warn fleet owners and drivers about cabs due for service by km';

    public function handle(CustomerNotifier $notifier): int
    {
        if (!Modules::fleet() || !Modules::customerApp()) {
            $this->info('Fleets or the customer app are switched off.');

            return self::SUCCESS;
        }

        $warn = max(0, (int) WorkshopSettings::get('fleet.service_due_km_warning'));
        $sent = 0;

        Vehicle::whereNotNull('next_service_due_km')->where('is_active', true)
            ->whereHas('customer', fn ($q) => $q->where('is_fleet', true)->where('is_active', true))
            ->lazyById()
            ->each(function (Vehicle $v) use ($notifier, $warn, &$sent) {
                $km = OdometerReading::latestFor($v->id);
                if (!$km) {
                    return;
                }
                $left = $v->next_service_due_km - $km->km;
                if ($left > $warn) {
                    return;
                }
                // Two steps: due soon, then due now / overdue
                $step = $left <= 0 ? 'now' : 'soon';
                $title = $step === 'now'
                    ? "Service due now — {$v->registration_no}"
                    : "Service due in " . number_format($left) . " km — {$v->registration_no}";
                $body = 'Book it in the app: pickup & drop, or bring the cab in.';
                $dedupe = "fleet_km:{$v->id}:{$v->next_service_due_km}:{$step}";

                if ($notifier->notify($v->customer_id, 'service_due', $title, $body, '/app/book?vehicle=' . $v->id, $dedupe)) {
                    $sent++;
                }
                FleetDriver::where('customer_id', $v->customer_id)->where('is_active', true)
                    ->whereHas('vehicles', fn ($q) => $q->where('vehicles.id', $v->id))
                    ->pluck('id')
                    ->each(fn ($driverId) => $notifier->notify($v->customer_id, 'service_due', $title, $body, '/app/book?vehicle=' . $v->id, $dedupe, $driverId) && $sent++);
            });

        $this->line("service_due_km: {$sent}");

        return self::SUCCESS;
    }
}
