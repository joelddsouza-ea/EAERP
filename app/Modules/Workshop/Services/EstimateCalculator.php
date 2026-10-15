<?php

namespace App\Modules\Workshop\Services;

use App\Modules\Workshop\Models\JobCardEstimate;
use App\Modules\Workshop\Models\JobCardEstimateLine as Line;

/**
 * Estimate arithmetic, as in the owner's sheet:
 *  - plan block = oil + plan items + extras inside the plan + labour, where the
 *    labour line absorbs the difference so the block always equals the package price;
 *  - additional work is billed on top (declined lines excluded);
 *  - discounts (% and flat) apply separately to the plan and to additional work;
 *  - the grand total is rounded to the rupee.
 */
class EstimateCalculator
{
    public function recalculate(JobCardEstimate $estimate): JobCardEstimate
    {
        $lines = $estimate->lines()->get();
        $planSum = 0.0;
        $labour = null;

        foreach ($lines as $line) {
            if ($line->kind === Line::KIND_LABOUR_BALANCE) {
                $labour = $line;
                continue;
            }

            $amount = round((float) $line->quantity * (float) $line->rate, 2);
            if ((float) $line->amount !== $amount) {
                $line->update(['amount' => $amount]);
            }

            if ($line->section === Line::SECTION_PLAN) {
                $planSum += $amount;
            }
        }

        if ($labour) {
            $balance = round((float) $estimate->package_price - $planSum, 2);
            $labour->update(['quantity' => 1, 'rate' => $balance, 'amount' => $balance]);
        }

        $extrasBase = $lines
            ->filter(fn (Line $l) => $l->isAdditional() && $l->approval_status !== Line::DECLINED)
            ->sum(fn (Line $l) => (float) $l->amount);

        $planTotal = $this->afterDiscount((float) $estimate->package_price, (float) $estimate->plan_discount_pct, (float) $estimate->plan_discount_flat);
        $extrasTotal = $this->afterDiscount($extrasBase, (float) $estimate->extras_discount_pct, (float) $estimate->extras_discount_flat);

        $estimate->update([
            'plan_total' => $planTotal,
            'extras_total' => $extrasTotal,
            'grand_total' => round($planTotal + $extrasTotal),
        ]);

        return $estimate;
    }

    /**
     * Discount amounts actually given (used for the job card's discount field).
     */
    public function discountAmount(JobCardEstimate $estimate): float
    {
        $extrasBase = $estimate->lines
            ->filter(fn (Line $l) => $l->isAdditional() && $l->approval_status !== Line::DECLINED)
            ->sum(fn (Line $l) => (float) $l->amount);

        return round(
            ((float) $estimate->package_price - (float) $estimate->plan_total) + ($extrasBase - (float) $estimate->extras_total),
            2
        );
    }

    /**
     * Problems the advisor should see before sending.
     *
     * @return string[]
     */
    public function warnings(JobCardEstimate $estimate): array
    {
        $warnings = [];

        if ($estimate->package_id && (float) $estimate->package_price <= 0) {
            $warnings[] = 'This plan is not offered for this car type and oil grade. Check the price list in Pricing masters.';
        }

        $labour = $estimate->lines->firstWhere('kind', Line::KIND_LABOUR_BALANCE);
        if ($labour && (float) $labour->amount < 0) {
            $warnings[] = 'Plan item amounts exceed the package price, so the labour line is negative. The owner should fix the plan items in Pricing masters.';
        }

        return $warnings;
    }

    private function afterDiscount(float $base, float $pct, float $flat): float
    {
        // % discount is rounded to the rupee, as on the sheet
        return max(0, round($base - round($base * $pct / 100) - $flat, 2));
    }
}
