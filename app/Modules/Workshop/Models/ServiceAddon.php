<?php

namespace App\Modules\Workshop\Models;

use App\Core\Models\BaseModel;

class ServiceAddon extends BaseModel
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'price',
        'is_active',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'is_active' => 'boolean',
    ];
}
