<?php

namespace App\Modules\Workshop\Models;

use Illuminate\Database\Eloquent\Model;

class ChecklistSection extends Model
{
    protected $fillable = [
        'template_id',
        'name',
        'sort_order',
    ];

    /**
     * Template.
     */
    public function template()
    {
        return $this->belongsTo(ChecklistTemplate::class, 'template_id');
    }

    /**
     * Items in this section.
     */
    public function items()
    {
        return $this->hasMany(ChecklistItem::class, 'section_id')
            ->orderBy('sort_order');
    }
}
