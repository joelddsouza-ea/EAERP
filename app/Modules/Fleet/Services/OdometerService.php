<?php

namespace App\Modules\Fleet\Services;

use App\Core\Models\User;
use App\Core\Services\FileStorageService;
use App\Modules\Fleet\Models\FleetDriver;
use App\Modules\Fleet\Models\OdometerReading;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\Vehicle;
use Illuminate\Http\UploadedFile;

/**
 * Km readings of a car: drivers and owners log them in the app (with a dashboard photo if
 * they like), job cards add theirs. The latest reading feeds the service-due date and the
 * fleet's "service due soon" warnings.
 */
class OdometerService
{
    /** A reading this far below the last one is refused (typo); a new odometer is set by staff */
    public const MAX_DROP_KM = 50;

    public function __construct(protected FileStorageService $files) {}

    public function log(Vehicle $vehicle, int $km, string $source, ?FleetDriver $driver = null, ?UploadedFile $photo = null, ?User $user = null, ?int $jobCardId = null): OdometerReading
    {
        if ($km < 0 || $km > 3_000_000) {
            throw new FleetException('Enter the km shown on the odometer.', ['km' => ['Not a valid reading.']]);
        }
        $last = OdometerReading::latestFor($vehicle->id);
        if ($source !== 'job_card' && $source !== 'staff' && $last && $km < $last->km - self::MAX_DROP_KM) {
            throw new FleetException('That is less than the last reading (' . number_format($last->km) . ' km). Check the number, or ask the workshop to correct it.', ['km' => ['Below the last reading.']]);
        }

        $stored = $photo ? $this->files->uploadPrivateMedia($photo, "odometer/{$vehicle->id}") : null;

        return OdometerReading::create([
            'vehicle_id' => $vehicle->id,
            'km' => $km,
            'source' => $source,
            'fleet_driver_id' => $driver?->id,
            'job_card_id' => $jobCardId,
            'user_id' => $user?->id,
            'photo_disk' => $stored['disk'] ?? null,
            'photo_path' => $stored['path'] ?? null,
            'read_at' => now(),
        ]);
    }

    /**
     * A job card's km reading (written once per job card).
     */
    public function fromJobCard(JobCard $job): void
    {
        if (!$job->vehicle_id || !$job->km_reading || OdometerReading::where('job_card_id', $job->id)->exists()) {
            return;
        }
        OdometerReading::create([
            'vehicle_id' => $job->vehicle_id,
            'km' => (int) $job->km_reading,
            'source' => 'job_card',
            'job_card_id' => $job->id,
            'fleet_driver_id' => $job->fleet_driver_id,
            'read_at' => $job->created_at ?? now(),
        ]);
    }

    /**
     * @return array<int, array>
     */
    public function history(Vehicle $vehicle, int $limit = 20): array
    {
        return OdometerReading::with('driver:id,name')->where('vehicle_id', $vehicle->id)
            ->orderByDesc('read_at')->orderByDesc('id')->limit($limit)->get()
            ->map(fn (OdometerReading $r) => $this->present($r))->values()->all();
    }

    public function present(OdometerReading $r): array
    {
        return [
            'id' => $r->id,
            'km' => $r->km,
            'source' => $r->source,
            'by' => $r->driver?->name ?? match ($r->source) {
                'job_card' => 'Workshop (job card)',
                'owner' => 'Owner',
                default => 'Workshop',
            },
            'read_at' => $r->read_at?->toIso8601String(),
            'photo_url' => $r->photoUrl(),
        ];
    }
}
