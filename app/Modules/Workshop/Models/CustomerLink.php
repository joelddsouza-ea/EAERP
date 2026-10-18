<?php

namespace App\Modules\Workshop\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Login-free link a customer uses to follow their job and approve work.
 * Looked up by the sha256 of the token; the token is also kept encrypted so
 * later messages (ready, thank you) can repeat the same link.
 */
class CustomerLink extends Model
{
    protected $fillable = [
        'job_card_id',
        'token',
        'expires_at',
        'revoked_at',
        'last_viewed_at',
        'created_by',
    ];

    protected $hidden = [
        'token_hash',
        'token_encrypted',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'last_viewed_at' => 'datetime',
    ];

    public function jobCard()
    {
        return $this->belongsTo(JobCard::class);
    }

    public function getTokenAttribute(): ?string
    {
        return $this->token_encrypted ? Crypt::decryptString($this->token_encrypted) : null;
    }

    public function setTokenAttribute(string $token): void
    {
        $this->attributes['token_encrypted'] = Crypt::encryptString($token);
        $this->attributes['token_hash'] = self::hashToken($token);
    }

    public function url(): string
    {
        return \App\Core\Support\PublicUrl::to('/j/' . $this->token);
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function newToken(): string
    {
        return Str::random(40);
    }

    public function isUsable(): bool
    {
        return !$this->revoked_at && (!$this->expires_at || $this->expires_at->isFuture());
    }
}
