<?php

namespace App\Modules\Workshop\Services;

use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\LabourRate;
use App\Modules\Workshop\Models\LabourService;
use Illuminate\Support\Collection;

/**
 * Looks up GST-inclusive labour charges for a job's car (car type × fuel group).
 */
class LabourRateResolver
{
    /**
     * Active labour services with the rate for this job's car.
     * rate is null when the service is not offered for the car or the car type is unknown.
     *
     * @return Collection<int, array{labour_service_id:int, description:string, unit:string, rate:?float}>
     */
    public function forJobCard(JobCard $jobCard, ?string $search = null): Collection
    {
        $jobCard->loadMissing('vehicle');
        $categoryId = $jobCard->vehicle?->category_id;
        $fuel = LabourRate::fuelGroupFor($jobCard->vehicle?->fuel_type);

        $like = $search !== null ? '%' . addcslashes($search, '%_\\') . '%' : null;

        return LabourService::active()
            ->when($like, fn ($q) => $q->where('name', 'like', $like))
            ->with(['rates' => fn ($q) => $q->where('category_id', $categoryId)->where('fuel_group', $fuel)])
            ->orderBy('sort_order')->orderBy('id')
            ->get()
            ->map(fn (LabourService $service) => [
                'labour_service_id' => $service->id,
                'description' => $service->name,
                'unit' => $service->unit,
                'rate' => ($rate = $service->rates->first()?->rate) === null ? null : (float) $rate,
            ]);
    }
}
