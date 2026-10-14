<?php

namespace App\Modules\Workshop\Workflow\Guards;

use App\Modules\Workshop\Models\ChecklistTemplate;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Services\JobSheetRuleEngine;
use App\Modules\Workshop\Workflow\Guard;
use App\Modules\Workshop\Workflow\GuardFailure;

/**
 * The advisor's pre-delivery checklist must be fully answered with the expected answers.
 */
class PreDeliveryChecklistComplete implements Guard
{
    public function __construct(private JobSheetRuleEngine $rules) {}

    public function check(JobCard $jobCard): ?GuardFailure
    {
        $sheets = $jobCard->checklists()
            ->where('sheet_kind', ChecklistTemplate::KIND_PRE_DELIVERY)
            ->with('items.media')
            ->get();

        $problems = [];

        foreach ($sheets as $sheet) {
            foreach ($this->rules->sheetProblems($sheet, $sheet->completionRule()) as $problem) {
                $problems[] = ['sheet_id' => $sheet->id, 'sheet_name' => $sheet->name, ...$problem];
            }
        }

        return $problems
            ? new GuardFailure('pre_delivery_incomplete', 'The pre-delivery checklist is not complete.', ['items' => $problems])
            : null;
    }
}
