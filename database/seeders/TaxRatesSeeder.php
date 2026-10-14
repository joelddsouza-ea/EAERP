<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Modules\Billing\Models\TaxRate;

class TaxRatesSeeder extends Seeder
{
    public function run(): void
    {
        $taxRates = [
            [
                'name' => 'GST 18%',
                'code' => 'GST18',
                'rate' => 18.00,
                'is_gst' => true,
            ],
            [
                'name' => 'GST 12%',
                'code' => 'GST12',
                'rate' => 12.00,
                'is_gst' => true,
            ],
            [
                'name' => 'GST 5%',
                'code' => 'GST5',
                'rate' => 5.00,
                'is_gst' => true,
            ],
            [
                'name' => 'GST 28%',
                'code' => 'GST28',
                'rate' => 28.00,
                'is_gst' => true,
            ],
            [
                'name' => 'Exempt',
                'code' => 'EXEMPT',
                'rate' => 0.00,
                'is_gst' => false,
            ],
        ];

        foreach ($taxRates as $taxRate) {
            TaxRate::firstOrCreate(
                ['code' => $taxRate['code']],
                $taxRate
            );
        }
    }
}
