<?php

namespace App\Modules\Workshop\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Upload rules shared by job-sheet activity media and intake inventory media.
 */
trait ValidatesMediaUpload
{
    protected function validateMediaUpload(Request $request): void
    {
        $maxVideo = (int) config('workshop.job_sheets.max_video_kb');
        $maxPhoto = (int) config('workshop.job_sheets.max_photo_kb');

        $request->validate([
            'file' => [
                'required', 'file',
                'mimetypes:image/jpeg,image/png,image/webp,image/heic,image/heif,video/mp4,video/quicktime,video/webm',
                'max:' . max($maxVideo, $maxPhoto),
            ],
            'poster' => 'nullable|image|max:2048',
            'duration_seconds' => 'nullable|numeric|min:0|max:' . config('workshop.job_sheets.max_video_seconds'),
            'description' => 'nullable|string|max:255',
            'captured_at' => 'nullable|date',
        ]);

        $file = $request->file('file');
        $isVideo = str_starts_with((string) $file->getMimeType(), 'video/');
        if (!$isVideo && $file->getSize() > $maxPhoto * 1024) {
            throw ValidationException::withMessages([
                'file' => ['Photos may not be larger than ' . round($maxPhoto / 1024) . ' MB.'],
            ]);
        }
    }
}
