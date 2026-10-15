<?php

namespace App\Modules\Workshop\Http\Requests\Concerns;

use App\Modules\Workshop\Models\OilType;
use App\Modules\Workshop\Models\ServicePackage;
use App\Modules\Workshop\Models\Vehicle;
use Illuminate\Validation\Validator;

/**
 * A job card is either a service plan (service_type "scheduled" + package_id) or one
 * repair type. A plan needs an engine oil grade the plan is priced for on the car's type.
 */
trait ValidatesServicePlan
{
    public const SERVICE_TYPES = 'scheduled,general_repair,ac_service,body_shop,electrical,detailing,home_service';

    /**
     * @param array{service_type: ?string, package_id: ?int, oil_type_id: ?int, vehicle_id: ?int} $effective
     */
    protected function checkServicePlan(Validator $validator, array $effective): void
    {
        if ($effective['service_type'] !== 'scheduled') {
            return;
        }

        $package = $effective['package_id'] ? ServicePackage::find($effective['package_id']) : null;
        if (!$package || !$package->is_active) {
            $validator->errors()->add('package_id', 'Choose the service plan.');

            return;
        }

        if (!$effective['oil_type_id']) {
            $validator->errors()->add('oil_type_id', 'Choose the engine oil type for the service plan.');

            return;
        }

        $categoryId = $effective['vehicle_id'] ? Vehicle::whereKey($effective['vehicle_id'])->value('category_id') : null;
        if (!$categoryId) {
            $validator->errors()->add('vehicle_id', 'Set the car type on the vehicle so the plan can be priced.');

            return;
        }

        if ($package->getPriceFor($categoryId, $effective['oil_type_id']) === null) {
            $oil = OilType::whereKey($effective['oil_type_id'])->value('name');
            $validator->errors()->add(
                'oil_type_id',
                "{$package->name} isn't priced for this car type with {$oil} oil. Pick another grade, or set the price in Price Masters."
            );
        }
    }
}
