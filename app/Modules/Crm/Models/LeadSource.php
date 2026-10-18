<?php

namespace App\Modules\Crm\Models;

use Illuminate\Database\Eloquent\Model;

class LeadSource extends Model
{
    protected $fillable = ['name', 'slug', 'is_paid', 'webhook_key', 'is_active', 'sort_order'];

    protected $casts = [
        'is_paid' => 'boolean',
        'is_active' => 'boolean',
    ];

    protected $hidden = ['webhook_key'];

    public function campaigns()
    {
        return $this->hasMany(Campaign::class);
    }

    public static function bySlug(string $slug): ?self
    {
        return static::where('slug', $slug)->first();
    }

    public function webhookUrl(): string
    {
        return \App\Core\Support\PublicUrl::to("/api/v1/public/leads/{$this->webhook_key}");
    }
}
