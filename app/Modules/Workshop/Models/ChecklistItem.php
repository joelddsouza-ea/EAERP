<?php

namespace App\Modules\Workshop\Models;

use Illuminate\Database\Eloquent\Model;

class ChecklistItem extends Model
{
    protected $fillable = [
        'section_id',
        'item_name',
        'description',
        'input_type',
        'options',
        'is_required',
        'requires_photo',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'options' => 'array',
        'is_required' => 'boolean',
        'requires_photo' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * Section.
     */
    public function section()
    {
        return $this->belongsTo(ChecklistSection::class, 'section_id');
    }
}
