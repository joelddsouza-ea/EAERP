<?php

namespace App\Modules\Crm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * A login-free link sent to a lead or customer: book a slot, rate the service, view a
 * creative, or a referral page. The token is stored hashed (lookup) and encrypted (so a
 * later message can repeat the same link). Opens are counted (they warm the lead up).
 */
class CrmLink extends Model
{
    public const BOOK = 'book';
    public const FEEDBACK = 'feedback';
    public const CREATIVE = 'creative';
    public const REFERRAL = 'referral';

    protected $fillable = [
        'token_hash', 'token_encrypted', 'purpose', 'lead_id', 'customer_id', 'vehicle_id', 'job_card_id',
        'creative_id', 'package_id', 'branch_id', 'expires_at', 'first_opened_at', 'opens',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'first_opened_at' => 'datetime',
        'opens' => 'integer',
    ];

    protected $hidden = ['token_hash', 'token_encrypted'];

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function issue(array $attributes): self
    {
        $token = Str::random(32);

        return static::create([
            ...$attributes,
            'token_hash' => self::hashToken($token),
            'token_encrypted' => Crypt::encryptString($token),
        ]);
    }

    public function token(): string
    {
        return Crypt::decryptString($this->token_encrypted);
    }

    public function url(): string
    {
        return \App\Core\Support\PublicUrl::base() !== null
            ? \App\Core\Support\PublicUrl::to('/c/' . $this->token())
            : rtrim(config('app.url'), '/') . '/c/' . $this->token();
    }

    public function isUsable(): bool
    {
        return !$this->expires_at || $this->expires_at->isFuture();
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function creative()
    {
        return $this->belongsTo(Creative::class);
    }
}
