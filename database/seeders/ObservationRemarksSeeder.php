<?php

namespace Database\Seeders;

use App\Modules\Workshop\Models\ObservationRemark;
use Illuminate\Database\Seeder;

/**
 * Remarks for observations on the report. Rows edited in the app are kept.
 */
class ObservationRemarksSeeder extends Seeder
{
    private const REMARKS = [
        ['customer_not_approved', 'Customer not approved', false],
        ['fix_at_next_service', 'Fix at next service', false],
        ['fix_within_days', 'Fix within _ days', true],
        ['fix_if_worsens', 'Fix if the problem worsens', false],
        ['monitor', 'Monitor — check at next service', false],
        ['safety_critical', 'Safety critical — fix immediately', false],
        ['customer_to_arrange_part', 'Customer to arrange the part', false],
        ['part_on_back_order', 'Part on back-order — fit on arrival', false],
        ['not_covered_by_plan', 'Not covered by the plan — advised', false],
        ['for_information', 'For information only', false],
    ];

    public function run(): void
    {
        foreach (self::REMARKS as $i => [$code, $label, $needsDays]) {
            $remark = ObservationRemark::firstOrNew(['code' => $code]);
            if (!$remark->customized_at) {
                $remark->fill(['label' => $label, 'needs_days' => $needsDays, 'sort_order' => $i + 1, 'is_active' => true])->save();
            }
        }
    }
}
