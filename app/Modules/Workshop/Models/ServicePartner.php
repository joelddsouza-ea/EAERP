<?php

namespace App\Modules\Workshop\Models;

use App\Core\Models\Branch;
use App\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An outside partner the workshop sends cars to: washing, or wheel alignment & balancing.
 * Several per kind; the technician / advisor picks one per job. With a login (role
 * service-partner) they see the cars sent to them and upload photos/videos of their work.
 */
class ServicePartner extends Model
{
    use SoftDeletes;

    public const KIND_WASHING = 'washing';
    public const KIND_WHEEL_ALIGNMENT = 'wheel_alignment';

    public const KINDS = [
        self::KIND_WASHING => 'Washing',
        self::KIND_WHEEL_ALIGNMENT => 'Wheel alignment & balancing',
    ];

    protected $fillable = ['name', 'phone', 'kind', 'branch_id', 'user_id', 'is_active', 'notes'];

    protected $casts = ['is_active' => 'boolean'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function works()
    {
        return $this->hasMany(JobOutsideWork::class);
    }

    public function scopeForBranch($query, ?int $branchId)
    {
        return $query->where(fn ($q) => $q->whereNull('branch_id')->when($branchId, fn ($b) => $b->orWhere('branch_id', $branchId)));
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }
}
