<?php

namespace App\Modules\Workshop\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A job-sheet activity master. Media and N/A rules are editable by the owner;
 * job cards snapshot them when their sheet is created.
 */
class ChecklistItem extends Model
{
    public const MEDIA_NONE = 'none';
    public const MEDIA_PHOTO = 'photo';
    public const MEDIA_VIDEO = 'video';
    public const MEDIA_PHOTO_OR_VIDEO = 'photo_or_video';
    // Both: at least the minimum count of photos AND of videos
    public const MEDIA_PHOTO_AND_VIDEO = 'photo_and_video';

    public const MEDIA_REQUIREMENTS = [
        self::MEDIA_NONE,
        self::MEDIA_PHOTO,
        self::MEDIA_VIDEO,
        self::MEDIA_PHOTO_OR_VIDEO,
        self::MEDIA_PHOTO_AND_VIDEO,
    ];

    public const INPUT_TYPES = ['tick', 'yes_no', 'repair_replace', 'panel_matrix', 'text', 'number', 'select'];

    /**
     * Activities only possible at the workshop, by name (seeded; the owner edits the flag).
     */
    private const WORKSHOP_ONLY_PATTERN = '/(body|exterior) wash|wash completed|engine compartment washed|wheel arcs washed|wheel alignment|wheel balancing|alignment (&|and) balancing/i';

    public static function looksWorkshopOnly(string $name): bool
    {
        return (bool) preg_match(self::WORKSHOP_ONLY_PATTERN, $name);
    }

    /** Petrol / CNG engines only: N/A and locked on a diesel or electric car */
    public static function looksPetrolOnly(string $name): bool
    {
        return (bool) preg_match('/spark plug/i', $name);
    }

    /** "If applicable / if required / if needed": shown in another colour */
    public static function looksConditional(string $name): bool
    {
        return (bool) preg_match('/\bif (applicable|required|needed)\b/i', $name);
    }

    /** Fuel types a petrol-only point doesn't apply to */
    public const NO_SPARK_PLUG_FUELS = ['diesel', 'electric'];

    protected $fillable = [
        'section_id',
        'code',
        'item_name',
        'description',
        'input_type',
        'options',
        'is_required',
        'requires_photo',
        'media_requirement',
        'min_media_count',
        'na_allowed',
        'workshop_only',
        // Spark plugs etc.: N/A and locked on diesel / electric cars
        'petrol_only',
        // "If applicable / required / needed": another colour on the job sheet
        'conditional',
        'expected_answer',
        'applies_to_package_ids',
        'source_marker',
        'rules_customized_at',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'options' => 'array',
        'applies_to_package_ids' => 'array',
        'is_required' => 'boolean',
        'requires_photo' => 'boolean',
        'na_allowed' => 'boolean',
        'workshop_only' => 'boolean',
        'petrol_only' => 'boolean',
        'conditional' => 'boolean',
        'is_active' => 'boolean',
        'min_media_count' => 'integer',
        'rules_customized_at' => 'datetime',
    ];

    public function section()
    {
        return $this->belongsTo(ChecklistSection::class, 'section_id');
    }

    /**
     * Whether this activity applies to a job on the given plan.
     */
    public function appliesToPackage(?int $packageId): bool
    {
        if (empty($this->applies_to_package_ids)) {
            return true;
        }

        return $packageId !== null && in_array($packageId, $this->applies_to_package_ids, true);
    }
}
