<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Modules\Workshop\Models\ServicePackage;
use App\Modules\Workshop\Models\PackagePricing;
use App\Modules\Workshop\Models\VehicleCategory;
use App\Modules\Workshop\Models\OilType;
use App\Modules\Workshop\Models\PackageLineItem;
use App\Modules\Workshop\Models\PackageLineItemPrice;
use App\Modules\Workshop\Models\PackageOilLitre;

class ServicePackagesSeeder extends Seeder
{
    public function run(): void
    {
        $packages = [
            [
                'name' => 'EA Refresh',
                'slug' => 'ea-refresh',
                'activity_points' => 18,
                'description' => 'Quick top-up & clean-up between services',
                'features' => [
                    'Engine oil top-up (up to 500ml)',
                    'Coolant top-up',
                    'Windshield washer top-up',
                    'Tyre pressure check',
                    'Exterior wash',
                    'Interior vacuum',
                    'Dashboard wipe',
                ],
                'home_service_available' => true,
                'sort_order' => 1,
            ],
            [
                'name' => 'EA Essentials',
                'slug' => 'ea-essentials',
                'activity_points' => 25,
                'description' => 'Regular oil-change with essentials',
                'features' => [
                    'Engine oil change',
                    'Oil filter replacement',
                    'Air filter cleaning',
                    'AC filter check',
                    'All fluid top-up',
                    'Battery check',
                    'Brake inspection',
                    'Exterior wash',
                    'Interior cleaning',
                ],
                'home_service_available' => true,
                'sort_order' => 2,
            ],
            [
                'name' => 'EA Pro',
                'slug' => 'ea-pro',
                'activity_points' => 45,
                'description' => 'Full mechanical + engine, brakes, wheels, cabin',
                'features' => [
                    'All EA Essentials features',
                    'Spark plug inspection',
                    'Fuel filter check',
                    'Brake pad inspection',
                    'Wheel alignment check',
                    'Suspension inspection',
                    'Steering check',
                    'Complete under-body inspection',
                    'Belt inspection',
                    'Exhaust system check',
                ],
                'home_service_available' => true,
                'sort_order' => 3,
            ],
            [
                'name' => 'EA 360',
                'slug' => 'ea-360',
                'activity_points' => 70,
                'description' => 'Deep health check - every system inspected',
                'features' => [
                    'All EA Pro features',
                    'Complete diagnostic scan',
                    'AC performance check',
                    'Electrical system check',
                    'Clutch inspection',
                    'Transmission fluid check',
                    'Power steering fluid',
                    'Detailed inspection report',
                    'Roadside inspection',
                ],
                'home_service_available' => false,
                'sort_order' => 4,
            ],
            [
                'name' => 'EA Elite',
                'slug' => 'ea-elite',
                'activity_points' => 80,
                'description' => 'Full service + full interior/exterior detailing',
                'features' => [
                    'All EA 360 features',
                    'Exterior detailing',
                    'Interior deep cleaning',
                    'Leather conditioning',
                    'Engine bay cleaning',
                    'Alloy wheel cleaning',
                    'Windshield treatment',
                    'Paint protection',
                ],
                'home_service_available' => false,
                'sort_order' => 5,
            ],
        ];

        // Service report goes to the customer only for 360 and ELITE (owner decision)
        $sendsReport = ['ea-360', 'ea-elite'];

        $packageIds = [];
        foreach ($packages as $packageData) {
            $package = ServicePackage::firstOrCreate(['slug' => $packageData['slug']], $packageData);

            if (!$package->customized_at) {
                $package->update([
                    'send_customer_report' => in_array($package->slug, $sendsReport, true),
                    'next_service_km' => $package->next_service_km ?? self::NEXT_SERVICE_KM,
                    'next_service_months' => $package->next_service_months ?? self::NEXT_SERVICE_MONTHS,
                ]);
            }

            $packageIds[$package->slug] = $package->id;
        }

        $priceList = require database_path('seeders/data/pricing/price_list.php');
        $categories = VehicleCategory::pluck('id', 'slug');
        $oilTypes = OilType::pluck('id', 'slug');

        $this->seedPackagePrices($priceList['package_prices'], $packageIds, $categories, $oilTypes);
        $this->seedOilLitres($priceList['oil_litres'], $packageIds, $categories);
        $this->seedLineItems($priceList['line_items'], $packageIds, $categories);
    }

    private const NEXT_SERVICE_KM = 10000;
    private const NEXT_SERVICE_MONTHS = 9;

    /**
     * Prices from the sheet. Combinations the sheet does not offer are removed,
     * unless the owner priced them in the app.
     */
    private function seedPackagePrices(array $rows, array $packageIds, $categories, $oilTypes): void
    {
        $keep = [];

        foreach ($rows as [$plan, $category, $oil, $price]) {
            $pricing = PackagePricing::firstOrNew([
                'package_id' => $packageIds[$plan],
                'category_id' => $categories[$category],
                'oil_type_id' => $oilTypes[$oil],
            ]);

            if (!$pricing->customized_at) {
                $pricing->price = $price;
                $pricing->save();
            }

            $keep[] = $pricing->id;
        }

        PackagePricing::whereIn('package_id', $packageIds)
            ->whereNotIn('id', $keep)
            ->whereNull('customized_at')
            ->delete();
    }

    /**
     * Litres: EA Refresh uses the first column of the sheet, every other plan the second.
     */
    private function seedOilLitres(array $litres, array $packageIds, $categories): void
    {
        foreach ($packageIds as $slug => $packageId) {
            foreach ($litres as $category => [$refresh, $other]) {
                $row = PackageOilLitre::firstOrNew(['package_id' => $packageId, 'category_id' => $categories[$category]]);
                if (!$row->customized_at) {
                    $row->litres = $slug === 'ea-refresh' ? $refresh : $other;
                    $row->save();
                }
            }
        }
    }

    private function seedLineItems(array $rows, array $packageIds, $categories): void
    {
        $order = [];

        foreach ($rows as [$plan, $description, $amounts]) {
            $item = PackageLineItem::firstOrNew(['package_id' => $packageIds[$plan], 'description' => $description]);
            $order[$plan] = ($order[$plan] ?? 0) + 1;

            if (!$item->customized_at) {
                $item->fill(['sort_order' => $order[$plan], 'is_active' => true])->save();
            }

            foreach ($amounts as $category => $amount) {
                $price = PackageLineItemPrice::firstOrNew(['line_item_id' => $item->id, 'category_id' => $categories[$category]]);
                if (!$price->customized_at) {
                    $price->amount = $amount;
                    $price->save();
                }
            }
        }
    }
}
