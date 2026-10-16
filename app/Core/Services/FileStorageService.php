<?php

namespace App\Core\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * File Storage Service
 *
 * Provides abstraction for file uploads and storage.
 * Supports local and S3-compatible storage.
 */
class FileStorageService
{
    protected string $defaultDisk;

    public function __construct()
    {
        $this->defaultDisk = config('filesystems.default');
    }

    /**
     * Upload a file.
     *
     * @param UploadedFile $file The file to upload
     * @param string $directory The directory to store in
     * @param string|null $disk The storage disk to use
     * @param string|null $filename Custom filename (without extension)
     * @return array File info including path and url
     */
    public function upload(
        UploadedFile $file,
        string $directory,
        ?string $disk = null,
        ?string $filename = null
    ): array {
        $disk = $disk ?? $this->defaultDisk;

        // Generate unique filename if not provided
        $extension = $file->getClientOriginalExtension();
        $filename = ($filename ?? Str::uuid()) . '.' . $extension;

        // Store the file
        $path = $file->storeAs($directory, $filename, $disk);

        return [
            'path' => $path,
            'url' => $this->getUrl($path, $disk),
            'filename' => $filename,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'disk' => $disk,
        ];
    }

    /**
     * Upload multiple files.
     *
     * @param array $files Array of UploadedFile
     * @param string $directory The directory to store in
     * @param string|null $disk The storage disk to use
     * @return array Array of file info
     */
    public function uploadMultiple(
        array $files,
        string $directory,
        ?string $disk = null
    ): array {
        $results = [];

        foreach ($files as $file) {
            if ($file instanceof UploadedFile) {
                $results[] = $this->upload($file, $directory, $disk);
            }
        }

        return $results;
    }

    /**
     * Delete a file.
     */
    public function delete(string $path, ?string $disk = null): bool
    {
        $disk = $disk ?? $this->defaultDisk;

        return Storage::disk($disk)->delete($path);
    }

    /**
     * Delete multiple files.
     */
    public function deleteMultiple(array $paths, ?string $disk = null): bool
    {
        $disk = $disk ?? $this->defaultDisk;

        return Storage::disk($disk)->delete($paths);
    }

    /**
     * Get file URL.
     */
    public function getUrl(string $path, ?string $disk = null): string
    {
        $disk = $disk ?? $this->defaultDisk;

        return Storage::disk($disk)->url($path);
    }

    /**
     * Get temporary URL (for private files).
     */
    public function getTemporaryUrl(
        string $path,
        int $minutes = 60,
        ?string $disk = null
    ): string {
        $disk = $disk ?? $this->defaultDisk;

        return Storage::disk($disk)->temporaryUrl(
            $path,
            now()->addMinutes($minutes)
        );
    }

    /**
     * Check if file exists.
     */
    public function exists(string $path, ?string $disk = null): bool
    {
        $disk = $disk ?? $this->defaultDisk;

        return Storage::disk($disk)->exists($path);
    }

    /**
     * Get file size in bytes.
     */
    public function size(string $path, ?string $disk = null): int
    {
        $disk = $disk ?? $this->defaultDisk;

        return Storage::disk($disk)->size($path);
    }

    /**
     * Copy file from one path to another.
     */
    public function copy(string $from, string $to, ?string $disk = null): bool
    {
        $disk = $disk ?? $this->defaultDisk;

        return Storage::disk($disk)->copy($from, $to);
    }

    /**
     * Move file from one path to another.
     */
    public function move(string $from, string $to, ?string $disk = null): bool
    {
        $disk = $disk ?? $this->defaultDisk;

        return Storage::disk($disk)->move($from, $to);
    }

    /**
     * Upload job card photo.
     */
    public function uploadJobCardPhoto(
        UploadedFile $file,
        int $jobCardId,
        string $type = 'general'
    ): array {
        $directory = "job_cards/{$jobCardId}/{$type}";

        return $this->upload($file, $directory, 'job_photos');
    }

    /**
     * Delete all photos for a job card.
     */
    /**
     * Store a job-sheet photo/video on the private job_media disk.
     *
     * The extension comes from the detected MIME type, not the client filename.
     * Photos get a 400px JPEG thumbnail; videos use the browser-captured poster frame.
     */
    public function uploadJobSheetMedia(
        UploadedFile $file,
        int $jobCardId,
        string $folder,
        ?UploadedFile $poster = null
    ): array {
        return $this->uploadPrivateMedia($file, "job_cards/{$jobCardId}/{$folder}", $poster);
    }

    /**
     * Store a photo/video (or a PDF bill) in a folder of the private job_media disk, with a
     * thumbnail for photos (and for videos from the poster frame).
     */
    public function uploadPrivateMedia(UploadedFile $file, string $directory, ?UploadedFile $poster = null): array
    {
        $disk = 'job_media';
        $name = (string) Str::uuid();
        $extension = $file->guessExtension() ?: 'bin';

        $path = $file->storeAs($directory, "{$name}.{$extension}", $disk);
        $info = [
            'disk' => $disk,
            'path' => $path,
            'thumbnail_path' => null,
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
            'width' => null,
            'height' => null,
        ];

        $thumbSource = str_starts_with((string) $info['mime'], 'image/') ? $file : $poster;

        if ($thumbSource) {
            try {
                $image = (new \Intervention\Image\ImageManager(new \Intervention\Image\Drivers\Gd\Driver()))
                    ->read($thumbSource->getRealPath());

                if ($thumbSource === $file) {
                    $info['width'] = $image->width();
                    $info['height'] = $image->height();
                }

                $thumbPath = "{$directory}/thumbs/{$name}.jpg";
                Storage::disk($disk)->put($thumbPath, (string) $image->scaleDown(400, 400)->toJpeg(75));
                $info['thumbnail_path'] = $thumbPath;
            } catch (\Throwable $e) {
                // HEIC and other formats GD can't decode still upload; they just have no thumbnail
                report($e);
            }
        }

        return $info;
    }

    public function deleteJobCardPhotos(int $jobCardId): bool
    {
        $directory = "job_cards/{$jobCardId}";

        return Storage::disk('job_photos')->deleteDirectory($directory);
    }
}
