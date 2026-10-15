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
        'intake_pending' => 'Entered when the job card is created.',
    ];

    /**
     * Placeholders the message may use.
     */
    public const PLACEHOLDERS = [':name', ':car', ':job', ':workshop', ':link', ':amount', ':balance', ':gate_pass'];

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
