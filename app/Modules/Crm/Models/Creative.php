<?php

namespace App\Modules\Crm\Models;

use App\Modules\Workshop\Models\ServicePackage;
use Illuminate\Database\Eloquent\Model;

/**
 * Something to send: an image / video / PDF with its caption, or just text. A plan's
 * brochure goes with that plan's quote. The WhatsApp template fields are for the
 * Business API later; with share links the caption carries a link to the file.
 */
class Creative extends Model
{
    public const KINDS = ['text', 'image', 'video', 'pdf'];

    protected $fillable = ['title', 'kind', 'file_disk', 'file_path', 'mime', 'caption', 'package_id', 'wa_template', 'wa_language', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function package()
    {
        return $this->belongsTo(ServicePackage::class, 'package_id');
    }

    public function hasFile(): bool
    {
        return $this->kind !== 'text' && (bool) $this->file_path;
    }
}
