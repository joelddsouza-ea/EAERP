<?php

namespace App\Modules\Workshop\Workflow\Guards;

use App\Core\Support\WorkshopSettings;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobCardMedia;
use App\Modules\Workshop\Workflow\Guard;
use App\Modules\Workshop\Workflow\GuardFailure;

/**
 * Intake must record the odometer and fuel level, the advisor's trial drive (or why
 * it was skipped), and the minimum inventory photos/videos before work starts.
 */
class IntakeCaptured implements Guard
{
    public function check(JobCard $jobCard): ?GuardFailure
    {
        // Intake was accepted when work first started (e.g. coming back from Awaiting Approval,
        // or jobs that started before these intake rules existed)
        if ($jobCard->work_started_at !== null) {
            return null;
        }

        $missing = array_keys(array_filter([
            'km_reading' => $jobCard->km_reading === null,
            'fuel_level' => $jobCard->fuel_level === null,
            'trial_drive' => $jobCard->intake_trial_status === null,
        ]));

        $counts = self::mediaCounts($jobCard);
        $needPhotos = max(0, self::minPhotos() - $counts['photo']);
        $needVideos = max(0, self::minVideos() - $counts['video']);

        if (!$missing && !$needPhotos && !$needVideos) {
            return null;
        }

        $todo = [];
        if (array_intersect($missing, ['km_reading', 'fuel_level'])) {
            $todo[] = 'the odometer reading and fuel level';
        }
        if (in_array('trial_drive', $missing, true)) {
            $todo[] = 'the trial drive (or why it was skipped)';
        }
        if ($needPhotos) {
            $todo[] = "{$needPhotos} more inventory photo" . ($needPhotos > 1 ? 's' : '');
        }
        if ($needVideos) {
            $todo[] = "{$needVideos} more inventory video" . ($needVideos > 1 ? 's' : '');
        }

        return new GuardFailure('intake_incomplete', 'Intake is not complete. Record ' . implode(', ', $todo) . '.', [
            'fields' => $missing,
            'photos_needed' => $needPhotos,
            'videos_needed' => $needVideos,
        ]);
    }

    /**
     * @return array{photo:int, video:int}
     */
    public static function mediaCounts(JobCard $jobCard): array
    {
        $counts = JobCardMedia::where('attachable_type', 'job_card')
            ->where('attachable_id', $jobCard->id)
            ->selectRaw('media_type, COUNT(*) as total')
            ->groupBy('media_type')
            ->pluck('total', 'media_type');

        return [
            'photo' => (int) ($counts[JobCardMedia::TYPE_PHOTO] ?? 0),
            'video' => (int) ($counts[JobCardMedia::TYPE_VIDEO] ?? 0),
        ];
    }

    public static function minPhotos(): int
    {
        return (int) WorkshopSettings::get('intake.min_photos');
    }

    public static function minVideos(): int
    {
        return (int) WorkshopSettings::get('intake.min_videos');
    }
}
