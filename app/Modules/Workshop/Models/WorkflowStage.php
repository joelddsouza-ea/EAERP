<?php

namespace App\Modules\Workshop\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Job stage master: whether the system moves a job into this status by itself,
 * what the stage waits for, and the WhatsApp message the customer gets on entry.
 */
class WorkflowStage extends Model
{
    public const NOTIFY_OFF = 'off';
    public const NOTIFY_FIRST = 'first_entry';
    public const NOTIFY_ALWAYS = 'always';
    public const NOTIFY_MODES = [self::NOTIFY_OFF, self::NOTIFY_FIRST, self::NOTIFY_ALWAYS];

    /**
     * Stages the system can enter by itself (the others are entered by people:
     * a new job starts at Intake Pending; cancelling always needs a reason).
     */
    public const AUTO_CAPABLE = [
        'intake_in_progress', 'awaiting_approval', 'work_in_progress', 'awaiting_parts', 'post_work_trial',
        'wash_pending', 'qc_pending', 'ready_for_delivery', 'invoiced', 'gate_pass_generated', 'delivered',
    ];

    /**
     * Stages entered by a person's decision, not by the system.
     */
    public const ENTERED_BY = [
        'pickup_scheduled' => 'Entered when a pickup & drop job card is created.',
        'pickup_en_route' => 'Entered when the driver taps Start on the pickup.',
        'vehicle_collected' => 'Entered when the driver taps Collected (odometer, fuel and photos/videos recorded).',
        'home_visit_scheduled' => 'Entered when a home-service job card is created.',
        'home_visit_en_route' => 'Entered when the technician taps Start on the home visit.',
        'intake_pending' => 'Entered when the job card is created (or the driver reaches the workshop / the technician arrives).',
    ];

    /**
     * Placeholders the message may use.
     */
    public const PLACEHOLDERS = [
        ':name', ':car', ':job', ':workshop', ':link', ':amount', ':balance', ':gate_pass',
        ':driver', ':driver_phone', ':slot', ':policy',
    ];

    protected $fillable = ['status', 'label', 'waiting_for', 'auto', 'notify_customer', 'message', 'gallery_update', 'sort_order', 'customized_at'];

    protected $casts = [
        'auto' => 'boolean',
        'gallery_update' => 'boolean',
        'sort_order' => 'integer',
        'customized_at' => 'datetime',
    ];

    /** @var array<string, self>|null per-request cache */
    private static ?array $cache = null;

    public static function for(string $status): ?self
    {
        self::$cache ??= static::all()->keyBy('status')->all();

        return self::$cache[$status] ?? null;
    }

    public static function flushCache(): void
    {
        self::$cache = null;
    }

    /**
     * Whether the system may move a job into this status by itself.
     */
    public static function isAuto(string $status): bool
    {
        if (!in_array($status, self::AUTO_CAPABLE, true)) {
            return false;
        }

        // No row (e.g. a status added later): automatic by default
        return self::for($status)?->auto ?? true;
    }

    protected static function booted(): void
    {
        static::saved(fn () => self::flushCache());
    }
}
