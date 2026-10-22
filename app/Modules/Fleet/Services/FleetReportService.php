<?php

namespace App\Modules\Fleet\Services;

use App\Core\Models\Branch;
use App\Core\Models\User;
use App\Core\Services\FileStorageService;
use App\Core\Support\Modules;
use App\Modules\Crm\Services\BookingService;
use App\Modules\Crm\Services\CrmException;
use App\Modules\CustomerApp\Services\CustomerNotifier;
use App\Modules\CustomerApp\Support\AppViewer;
use App\Modules\Fleet\Models\FleetReport;
use App\Modules\Fleet\Models\FleetReportMedia;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Notifications\StaffAlert;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Problems drivers (or the fleet owner) report on a cab from the app: what is wrong, photos /
 * videos, km and, for a breakdown, where the cab is. Staff book it in (drive-in or pickup),
 * or close it with a note; a job card opened for the cab picks the report up by itself.
 * The reporting driver and the owner hear about every step in the app.
 */
class FleetReportService
{
    public const MAX_MEDIA = 6;

    /** What kind of booking each problem becomes */
    public const SERVICE_TYPES = [
        'breakdown' => 'general_repair',
        'warning_light' => 'electrical',
        'noise' => 'general_repair',
        'accident' => 'body_shop',
        'tyre' => 'general_repair',
        'ac' => 'ac_service',
        'other' => 'general_repair',
    ];

    public function __construct(
        protected FileStorageService $files,
        protected CustomerNotifier $notifier,
        protected OdometerService $odometer,
    ) {}

    /**
     * @param array{vehicle_id: int, kind: string, description: string, odometer_km?: ?int, lat?: ?float, lng?: ?float, location_text?: ?string} $data
     * @param array<int, UploadedFile> $media
     */
    public function create(AppViewer $viewer, array $data, array $media = []): FleetReport
    {
        $vehicle = $viewer->vehicle((int) $data['vehicle_id']);
        if (count($media) > self::MAX_MEDIA) {
            throw new FleetException('Add up to ' . self::MAX_MEDIA . ' photos or videos.');
        }

        $report = DB::transaction(function () use ($viewer, $vehicle, $data, $media) {
            $report = FleetReport::create([
                'number' => FleetReport::nextNumber(),
                'customer_id' => $viewer->customer->id,
                'vehicle_id' => $vehicle->id,
                'fleet_driver_id' => $viewer->driverId(),
                'branch_id' => $this->branchFor($vehicle->id, $viewer->customer->id),
                'kind' => $data['kind'],
                'description' => trim($data['description']),
                'odometer_km' => $data['odometer_km'] ?? null,
                'lat' => $data['lat'] ?? null,
                'lng' => $data['lng'] ?? null,
                'location_text' => $data['location_text'] ?? null,
                'status' => FleetReport::OPEN,
            ]);

            foreach ($media as $file) {
                $stored = $this->files->uploadPrivateMedia($file, "fleet-reports/{$report->id}");
                FleetReportMedia::create([
                    'fleet_report_id' => $report->id,
                    'media_type' => str_starts_with((string) $stored['mime'], 'video/') ? 'video' : 'photo',
                    'file_disk' => $stored['disk'],
                    'file_path' => $stored['path'],
                    'mime' => $stored['mime'],
                ]);
            }

            if (!empty($data['odometer_km'])) {
                try {
                    $this->odometer->log($vehicle, (int) $data['odometer_km'], $viewer->isDriver() ? 'driver' : 'owner', $viewer->driver);
                } catch (FleetException) {
                    // A doubtful km on a report is kept on the report only
                }
            }

            return $report;
        });

        $report->load(['vehicle:id,registration_no', 'driver:id,name,phone', 'customer:id,name,company_name,customer_type']);
        DB::afterCommit(fn () => $this->announce($report));

        return $report;
    }

    /**
     * Staff: book the cab in (drive-in or pickup from where it stands). Needs the CRM module.
     */
    public function book(FleetReport $report, array $data, User $user): FleetReport
    {
        if (!Modules::crm()) {
            throw new FleetException('Bookings need the CRM module. Open a job card for the cab instead.');
        }
        if ($report->status !== FleetReport::OPEN) {
            throw new FleetException('This report has already been dealt with.');
        }

        try {
            $booking = app(BookingService::class)->create([
                'customer_id' => $report->customer_id,
                'vehicle_id' => $report->vehicle_id,
                'branch_id' => $data['branch_id'] ?? $report->branch_id,
                'booking_date' => $data['booking_date'],
                'slot_from' => $data['slot_from'] ?? null,
                'service_type' => self::SERVICE_TYPES[$report->kind] ?? 'general_repair',
                'service_mode' => $data['service_mode'],
                'address' => $data['address'] ?? $report->location_text,
                'landmark' => $data['landmark'] ?? null,
                'maps_url' => $data['maps_url'] ?? ($report->lat !== null ? "https://maps.google.com/?q={$report->lat},{$report->lng}" : null),
                'notes' => mb_substr("Fleet report {$report->number}: " . FleetReport::KINDS[$report->kind] . ' — ' . $report->description, 0, 1000),
            ], $user, 'staff');
        } catch (CrmException $e) {
            throw new FleetException($e->getMessage(), $e->errors);
        }

        $report->update([
            'status' => $booking->job_card_id ? FleetReport::JOB_OPENED : FleetReport::BOOKED,
            'booking_id' => $booking->id,
            'job_card_id' => $booking->job_card_id,
            'handled_by' => $user->id,
            'handled_at' => now(),
            'staff_note' => $data['note'] ?? null,
        ]);

        $when = $booking->booking_date->format('d M') . ($booking->slot_from ? ', ' . substr($booking->slot_from, 0, 5) : '');
        $this->tellFleet($report, "Booked in: {$report->vehicle?->registration_no}", $booking->service_mode === JobCard::MODE_PICKUP_DROP
            ? "Our driver will pick the cab up ({$when})."
            : "Bring the cab to the workshop on {$when}.");

        return $report->fresh();
    }

    public function close(FleetReport $report, string $note, User $user): FleetReport
    {
        $report->update(['status' => FleetReport::CLOSED, 'staff_note' => $note, 'handled_by' => $user->id, 'handled_at' => now()]);
        $this->tellFleet($report, "Report closed: {$report->vehicle?->registration_no}", $note);

        return $report;
    }

    /**
     * A job card opened for the cab takes over its open (or booked) reports.
     */
    public function jobCardOpened(JobCard $job): void
    {
        if (!$job->vehicle_id) {
            return;
        }
        FleetReport::where('vehicle_id', $job->vehicle_id)->whereIn('status', [FleetReport::OPEN, FleetReport::BOOKED])
            ->get()
            ->each(function (FleetReport $r) use ($job) {
                $r->update(['status' => FleetReport::JOB_OPENED, 'job_card_id' => $job->id]);
                $this->tellFleet($r, "At the workshop: {$r->vehicle?->registration_no}", "Job {$job->job_number} is open for your report. Follow it in the app.", "/app/jobs/{$job->id}");
            });
    }

    public function present(FleetReport $r, bool $forStaff = false): array
    {
        $r->loadMissing(['vehicle:id,registration_no', 'driver:id,name,phone', 'media', 'booking:id,number,booking_date,slot_from,service_mode', 'jobCard:id,job_number,status']);
        $data = [
            'id' => $r->id,
            'number' => $r->number,
            'kind' => $r->kind,
            'kind_label' => FleetReport::KINDS[$r->kind] ?? $r->kind,
            'description' => $r->description,
            'vehicle' => ['id' => $r->vehicle_id, 'registration_no' => $r->vehicle?->registration_no],
            'driver' => $r->driver ? ['id' => $r->driver->id, 'name' => $r->driver->name, 'phone' => $r->driver->phone] : null,
            'odometer_km' => $r->odometer_km,
            'lat' => $r->lat,
            'lng' => $r->lng,
            'location_text' => $r->location_text,
            'maps_url' => $r->lat !== null ? "https://maps.google.com/?q={$r->lat},{$r->lng}" : null,
            'status' => $r->status,
            'status_label' => FleetReport::STATUS_LABELS[$r->status] ?? $r->status,
            'staff_note' => $r->staff_note,
            'booking' => $r->booking ? ['number' => $r->booking->number, 'date' => $r->booking->booking_date?->toDateString(), 'slot' => $r->booking->slot_from ? substr($r->booking->slot_from, 0, 5) : null, 'mode' => $r->booking->service_mode] : null,
            'job' => $r->jobCard ? ['id' => $r->jobCard->id, 'job_number' => $r->jobCard->job_number, 'status' => $r->jobCard->status] : null,
            'media' => $r->media->map(fn (FleetReportMedia $m) => ['id' => $m->id, 'media_type' => $m->media_type, 'url' => $m->url()])->values()->all(),
            'created_at' => $r->created_at?->toIso8601String(),
        ];
        if ($forStaff) {
            $r->loadMissing(['customer:id,name,company_name,customer_type,phone', 'handler:id,name']);
            $data += [
                'customer' => ['id' => $r->customer_id, 'name' => $r->customer?->display_name, 'phone' => $r->customer?->phone],
                'branch_id' => $r->branch_id,
                'handled_by' => $r->handler?->name,
                'handled_at' => $r->handled_at?->toIso8601String(),
            ];
        }

        return $data;
    }

    protected function announce(FleetReport $report): void
    {
        $reg = $report->vehicle?->registration_no;
        $who = $report->driver ? "driver {$report->driver->name}" : 'the fleet owner';
        $alert = new StaffAlert(
            'fleet_report',
            ($report->kind === 'breakdown' ? 'BREAKDOWN — ' : 'Fleet problem — ') . "{$reg} ({$report->customer?->display_name})",
            FleetReport::KINDS[$report->kind] . " reported by {$who}: " . mb_substr($report->description, 0, 160),
            '/fleet/reports?report=' . $report->id,
        );
        $this->desk($report->branch_id)->each->notify($alert);

        // The owner hears about a driver's report
        if ($report->fleet_driver_id) {
            $this->notifier->notify($report->customer_id, 'stage', "{$report->driver->firstName()} reported a problem on {$reg}", FleetReport::KINDS[$report->kind] . ': ' . mb_substr($report->description, 0, 200), '/app/fleet/reports');
        }
    }

    /**
     * Tell the reporting driver and the owner.
     */
    protected function tellFleet(FleetReport $report, string $title, ?string $body, string $link = '/app/fleet/reports'): void
    {
        $this->notifier->notify($report->customer_id, 'stage', $title, $body, $link);
        if ($report->fleet_driver_id) {
            $this->notifier->notify($report->customer_id, 'stage', $title, $body, $report->job_card_id ? "/app/jobs/{$report->job_card_id}" : '/app/reports', null, $report->fleet_driver_id);
        }
    }

    protected function desk(?int $branchId)
    {
        return User::active()
            ->whereHas('roles.permissions', fn ($q) => $q->where('slug', 'workshop.fleet.reports'))
            ->when($branchId, fn ($q) => $q->whereHas('branches', fn ($b) => $b->where('branches.id', $branchId)))
            ->limit(20)->get();
    }

    protected function branchFor(int $vehicleId, int $customerId): ?int
    {
        return JobCard::withoutGlobalScopes()->where('vehicle_id', $vehicleId)->latest('id')->value('branch_id')
            ?? JobCard::withoutGlobalScopes()->where('customer_id', $customerId)->latest('id')->value('branch_id')
            ?? Branch::active()->orderBy('id')->value('id');
    }
}
