<?php

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;

class Shift extends Model
{
    protected $fillable = ['name', 'start_time', 'end_time', 'break_minutes', 'late_grace_minutes', 'half_day_minutes', 'is_active'];

    protected $casts = [
        'break_minutes' => 'integer',
        'late_grace_minutes' => 'integer',
        'half_day_minutes' => 'integer',
        'is_active' => 'boolean',
    ];

    public static function defaultShift(): ?self
    {
        return static::where('is_active', true)->orderBy('id')->first();
    }

    /**
     * Shift length in minutes, after the break.
     */
    public function workMinutes(): int
    {
        [$sh, $sm] = array_map('intval', explode(':', $this->start_time));
        [$eh, $em] = array_map('intval', explode(':', $this->end_time));

        return max(0, ($eh * 60 + $em) - ($sh * 60 + $sm) - (int) $this->break_minutes);
    }
}
