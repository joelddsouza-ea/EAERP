<?php

namespace App\Modules\Workshop\Models;

use App\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\URL;

/**
 * A photo or video captured on a job card, usually for a job-sheet activity.
 * Files live on a private disk and are served through short-lived signed URLs.
 */
class JobCardMedia extends Model
{
    use SoftDeletes;

    public const TYPE_PHOTO = 'photo';
    public const TYPE_VIDEO = 'video';

    /** Photo/video of the new part after fitting (Additional Issues lines) */
    public const TAG_FITTED_PART = 'fitted_part';

    protected $table = 'job_card_media';

    protected $fillable = [
        'job_card_id',
        'attachable_type',
        'attachable_id',
        'media_type',
        'tag',
        'disk',
        'file_path',
        'thumbnail_path',
        'mime',
        'size_bytes',
        'duration_seconds',
        'width',
        'height',
        'description',
        'customer_visible',
        'uploaded_by',
        'captured_at',
    ];

    protected $casts = [
        'customer_visible' => 'boolean',
        'size_bytes' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'captured_at' => 'datetime',
    ];

    public function jobCard()
    {
        return $this->belongsTo(JobCard::class);
    }

    public function attachable()
    {
        return $this->morphTo();
    }

    public function uploadedBy()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function url(bool $thumbnail = false): ?string
    {
        if ($thumbnail && !$this->thumbnail_path) {
            return null;
        }

        return URL::temporarySignedRoute(
            'workshop.media.stream',
            now()->addMinutes((int) config('workshop.job_sheets.media_url_ttl', 30)),
            array_filter(['media' => $this->id, 'thumb' => $thumbnail ? 1 : null])
        );
    }
}
