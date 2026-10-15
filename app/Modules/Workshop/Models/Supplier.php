<?php

namespace App\Modules\Workshop\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Parts supplier the spare parts coordinator gets quotes from (and, from Phase 3, buys from).
 */
class Supplier extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'contact_person',
        'phone',
        'gstin',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function quotes()
    {
        return $this->hasMany(SparePartQuote::class);
    }
}
