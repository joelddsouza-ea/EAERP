<?php

namespace Tests\Concerns;

use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobCardMedia;
use App\Modules\Workshop\Services\JobLifecycleService;
use App\Modules\Workshop\Workflow\Guards\IntakeCaptured;

/**
 * Records a finished intake (trial drive + minimum inventory media) without going
 * through uploads, for tests that are about later stages. The job then moves on by
 * itself, as after an upload (to Work In Progress when a technician is assigned).
 */
trait CompletesIntake
{
    protected function completeIntake(JobCard $job, int $userId): void
    {
        $job->update([
            'intake_trial_status' => 'done',
            'intake_trial_by' => $userId,
            'intake_trial_at' => now(),
        ]);

        $needed = [
            JobCardMedia::TYPE_PHOTO => IntakeCaptured::minPhotos(),
            JobCardMedia::TYPE_VIDEO => IntakeCaptured::minVideos(),
        ];

        foreach ($needed as $type => $count) {
            for ($i = 0; $i < $count; $i++) {
                JobCardMedia::create([
                    'job_card_id' => $job->id,
                    'attachable_type' => 'job_card',
                    'attachable_id' => $job->id,
                    'media_type' => $type,
                    'disk' => 'job_media',
                    'file_path' => "intake/test-{$type}-{$i}",
                    'mime' => $type === JobCardMedia::TYPE_VIDEO ? 'video/mp4' : 'image/jpeg',
                    'size_bytes' => 1000,
                    'uploaded_by' => $userId,
                ]);
            }
        }

        app(JobLifecycleService::class)->sync($job->fresh());
    }
}
