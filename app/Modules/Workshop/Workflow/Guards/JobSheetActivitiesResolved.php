<?php

namespace App\Modules\Workshop\Workflow\Guards;

use App\Modules\Workshop\Models\ChecklistTemplate;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Services\JobSheetRuleEngine;
use App\Modules\Workshop\Workflow\Guard;
use App\Modules\Workshop\Workflow\GuardFailure;

/**
 * Every activity on the technician job sheets must be done (with required media)
 * or N/A before the trial drive. A submitted sheet counts as resolved, including
 * one a manager submitted with an override.
 */
class JobSheetActivitiesResolved implements Guard
{
    public function __construct(private JobSheetRuleEngine $rules) {}

    public function check(JobCard $jobCard): ?GuardFailure
    {
        $sheets = $jobCard->checklists()
            ->whereIn('sheet_kind', ChecklistTemplate::WORK_SHEET_KINDS)
            ->with('items.media')
            ->get();

        $problems = [];

        foreach ($sheets as $sheet) {
            if ($sheet->isCompleted()) {
                continue;
            }
            foreach ($this->rules->sheetProblems($sheet, $sheet->completionRule()) as $problem) {
                $problems[] = ['sheet_id' => $sheet->id, 'sheet_name' => $sheet->name, ...$problem];
            }
        }

        if (!$problems) {
            return null;
        }

        return new GuardFailure(
            'job_sheet_incomplete',
            count($problems) . ' job-sheet ' . (count($problems) === 1 ? 'activity is' : 'activities are') . ' not complete.',
            ['items' => $problems]
        );
    }
}
