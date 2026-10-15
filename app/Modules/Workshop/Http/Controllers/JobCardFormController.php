<?php

namespace App\Modules\Workshop\Http\Controllers;

use App\Core\Http\Controllers\Api\ApiController;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobCardEstimate;
use App\Modules\Workshop\Models\OilType;
use App\Modules\Workshop\Models\PackagePricing;
use App\Modules\Workshop\Models\ServicePackage;
use App\Modules\Workshop\Models\Vehicle;
use App\Modules\Workshop\Services\JobSheetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Choices for the Create / Edit Job Card form: service plans (with the oil grades
 * and prices offered for this car), repair types, and the job sheets each choice
 * brings — worked out by the same matching that attaches sheets on save.
 */
class JobCardFormController extends ApiController
{
    /** Non-plan service types, in menu order (labels mirror resources/js/constants/serviceTypes.ts) */
    private const REPAIR_TYPES = [
        'general_repair' => 'General / Mechanical Repair',
        'ac_service' => 'AC Service & Repair',
        'body_shop' => 'Body Shop / Accidental',
        'electrical' => 'Electrical',
        'detailing' => 'Detailing',
        'home_service' => 'Home Service',
    ];

    public function __construct(protected JobSheetService $sheets) {}

    public function options(Request $request): JsonResponse
    {
        $data = $request->validate([
            'vehicle_id' => 'nullable|integer|exists:vehicles,id',
            'job_card_id' => 'nullable|integer',
        ]);

        $vehicle = isset($data['vehicle_id']) ? Vehicle::with('category:id,name')->find($data['vehicle_id']) : null;
        $categoryId = $vehicle?->category_id;

        $oilTypes = OilType::where('is_active', true)->orderBy('sort_order')->get(['id', 'name']);
        $prices = $categoryId
            ? PackagePricing::where('category_id', $categoryId)->get(['package_id', 'oil_type_id', 'price'])->groupBy('package_id')
            : collect();

        $plans = ServicePackage::where('is_active', true)->orderBy('sort_order')->orderBy('id')->get(['id', 'name'])
            ->map(fn (ServicePackage $plan) => [
                'id' => $plan->id,
                'name' => $plan->name,
                'oil_options' => $oilTypes
                    ->map(fn (OilType $oil) => [
                        'id' => $oil->id,
                        'name' => $oil->name,
                        'price' => ($row = $prices->get($plan->id)?->firstWhere('oil_type_id', $oil->id)) ? (float) $row->price : null,
                    ])
                    ->filter(fn ($o) => $o['price'] !== null)
                    ->values(),
                'sheets' => $this->sheetNames(['service_type' => 'scheduled', 'package_id' => $plan->id]),
            ]);

        $repairTypes = collect(self::REPAIR_TYPES)->map(fn ($label, $value) => [
            'value' => $value,
            'label' => $label,
            'sheets' => $this->sheetNames(['service_type' => $value]),
        ])->values();

        return $this->success([
            'car_type' => $vehicle?->category ? ['id' => $vehicle->category->id, 'name' => $vehicle->category->name] : null,
            'plans' => $plans,
            'service_types' => $repairTypes,
            'oil_types' => $oilTypes,
            'lock' => $this->lock($data['job_card_id'] ?? null),
        ]);
    }

    /**
     * Names of the job sheets a job card with these attributes would get.
     */
    private function sheetNames(array $attributes): array
    {
        return $this->sheets->matchingTemplates(new JobCard($attributes))
            ->map(fn ($t) => preg_replace('/\s+—\s+.*$/u', '', $t->name))
            ->values()
            ->all();
    }

    private function lock(?int $jobCardId): ?array
    {
        if (!$jobCardId) {
            return null;
        }

        $sent = JobCardEstimate::where('job_card_id', $jobCardId)
            ->where('status', '!=', JobCardEstimate::STATUS_DRAFT)
            ->whereHas('jobCard')
            ->exists();

        return $sent
            ? ['reason' => 'An estimate has been sent to the customer, so the plan and oil grade are changed on the estimate (new version).']
            : null;
    }
}
