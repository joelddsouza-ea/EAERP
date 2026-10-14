<?php

namespace App\Console\Commands;

use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Services\JobSheetService;
use Illuminate\Console\Command;

/**
 * Gives existing job cards the current job sheets. Untouched sheets from inactive
 * (legacy placeholder) templates are replaced; sheets with any recorded work are kept.
 */
class AttachJobSheets extends Command
{
    protected $signature = 'workshop:attach-job-sheets
                            {--active : Only job cards that are not delivered or cancelled}
                            {--dry-run : Report what would change without writing}';

    protected $description = 'Attach job sheets from the current masters to existing job cards';

    public function handle(JobSheetService $sheets): int
    {
        $query = JobCard::withoutGlobalScopes()->with(['sheets.items.media', 'sheets.template']);
        if ($this->option('active')) {
            $query->active();
        }

        $dryRun = (bool) $this->option('dry-run');
        $replaced = $added = $kept = 0;

        foreach ($query->lazy(50) as $jobCard) {
            foreach ($jobCard->sheets as $sheet) {
                if ($sheet->template && $sheet->template->is_active) {
                    continue;
                }
                if ($sheets->isUntouched($sheet)) {
                    $replaced++;
                    if (!$dryRun) {
                        $sheet->delete();
                    }
                } else {
                    $kept++;
                    $this->line("  kept {$jobCard->job_number}: \"{$sheet->name}\" has recorded work");
                }
            }

            if ($dryRun) {
                $existing = $jobCard->sheets->pluck('template_id')->all();
                $added += $sheets->matchingTemplates($jobCard)->whereNotIn('id', $existing)->count();
            } else {
                $added += count($sheets->instantiateFor($jobCard));
            }
        }

        $this->info(($dryRun ? '[dry run] ' : '') . "Sheets added: {$added}; legacy sheets replaced: {$replaced}; legacy sheets kept: {$kept}");

        return self::SUCCESS;
    }
}
