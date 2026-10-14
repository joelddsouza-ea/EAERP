<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Modules\Workshop\Models\Bay;
use App\Core\Models\Branch;

class BaysSeeder extends Seeder
{
    public function run(): void
    {
        $branches = Branch::all();

        foreach ($branches as $branch) {
            // Create 4-6 bays per branch
            $bayCount = $branch->code === 'ANH' ? 6 : 4;

            for ($i = 1; $i <= $bayCount; $i++) {
                Bay::firstOrCreate(
                    [
                        'branch_id' => $branch->id,
                        'name' => "Bay {$i}",
                    ],
                    [
                        'code' => "{$branch->code}-B{$i}",
                        'type' => $this->getBayType($i),
                        'is_active' => true,
                    ]
                );
            }
        }
    }

    private function getBayType(int $index): string
    {
        // Valid types: general, detailing, body_shop, ac, electrical
        return match ($index) {
            1, 2, 3 => 'general',
            4 => 'detailing',
            5 => 'ac',
            6 => 'electrical',
            default => 'general',
        };
    }
}
