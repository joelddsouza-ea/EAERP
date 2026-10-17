<?php

namespace App\Modules\Accounts\Models;

use App\Core\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * A locked month: nothing is posted into it any more.
 */
class AccountingPeriod extends Model
{
    protected $fillable = ['period', 'locked_by', 'locked_at'];

    protected $casts = ['locked_at' => 'datetime'];

    public function lockedBy()
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    public static function isLocked(string $date): bool
    {
        return static::where('period', substr($date, 0, 7))->whereNotNull('locked_at')->exists();
    }
}
