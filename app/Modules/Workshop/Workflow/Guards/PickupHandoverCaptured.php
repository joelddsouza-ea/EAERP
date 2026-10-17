<?php

namespace App\Modules\Workshop\Workflow\Guards;

use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Workflow\Guard;
use App\Modules\Workshop\Workflow\GuardFailure;

/**
 * Before the driver leaves with the car: odometer, fuel and at least the intake
 * minimum of inventory photos/videos (there is no maximum). This is the job's intake,
 * so the advisor does not repeat it at the workshop.
 */
class PickupHandoverCaptured implements Guard
{
    public function check(JobCard $jobCard): ?GuardFailure
    {
        $todo = [];

        if ($jobCard->km_reading === null || $jobCard->fuel_level === null) {
            $todo[] = 'the odometer reading and fuel level';
        }

        $counts = IntakeCaptured::mediaCounts($jobCard);
        $needPhotos = max(0, IntakeCaptured::minPhotos() - $counts['photo']);
        $needVideos = max(0, IntakeCaptured::minVideos() - $counts['video']);

        if ($needPhotos) {
            $todo[] = "{$needPhotos} more photo" . ($needPhotos > 1 ? 's' : '');
        }
        if ($needVideos) {
            $todo[] = "{$needVideos} more video" . ($needVideos > 1 ? 's' : '');
        }

        if (!$todo) {
            return null;
        }

        return new GuardFailure('pickup_incomplete', 'Before leaving with the car, record ' . implode(', ', $todo) . '.', [
            'photos_needed' => $needPhotos,
            'videos_needed' => $needVideos,
        ]);
    }
}
