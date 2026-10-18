<?php

namespace App\Modules\CustomerApp\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * "Why does my car need this?" for one estimate line, written once and kept.
 */
class AiExplanation extends Model
{
    protected $fillable = ['estimate_id', 'line_key', 'text'];
}
