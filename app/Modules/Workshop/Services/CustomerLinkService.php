<?php

namespace App\Modules\Workshop\Services;

use App\Core\Models\User;
use App\Modules\Workshop\Models\CustomerLink;
use App\Modules\Workshop\Models\JobCard;

/**
 * One active login-free link per job card. Every message that carries the link
 * extends it, so it stays valid for link_valid_days after the last message
 * (the thank-you message at delivery).
 */
class CustomerLinkService
{
    public function activeFor(JobCard $jobCard): ?CustomerLink
    {
        return CustomerLink::where('job_card_id', $jobCard->id)
            ->whereNull('revoked_at')
            ->latest('id')
            ->first();
    }

    /**
     * The usable link for the job, created (or renewed) when needed.
     */
    public function ensure(JobCard $jobCard, ?User $user = null): CustomerLink
    {
        $link = $this->activeFor($jobCard);

        if (!$link) {
            return $this->create($jobCard, $user);
        }

        $this->extend($link);

        return $link;
    }

    /**
     * Revoke the current link and issue a new one (e.g. it was forwarded by mistake).
     */
    public function regenerate(JobCard $jobCard, ?User $user = null): CustomerLink
    {
        $this->revoke($jobCard);

        return $this->create($jobCard, $user);
    }

    public function revoke(JobCard $jobCard): void
    {
        CustomerLink::where('job_card_id', $jobCard->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    /**
     * Find the job card for a token from the URL. Null for unknown, revoked or expired links.
     */
    public function resolve(string $token): ?CustomerLink
    {
        if (strlen($token) !== 40) {
            return null;
        }

        $link = CustomerLink::where('token_hash', CustomerLink::hashToken($token))->first();

        return $link && $link->isUsable() ? $link : null;
    }

    protected function create(JobCard $jobCard, ?User $user): CustomerLink
    {
        return CustomerLink::create([
            'job_card_id' => $jobCard->id,
            'token' => CustomerLink::newToken(),
            'expires_at' => $this->expiry(),
            'created_by' => $user?->id,
        ]);
    }

    protected function extend(CustomerLink $link): void
    {
        $expiry = $this->expiry();

        if ($link->expires_at && $link->expires_at->lt($expiry)) {
            $link->update(['expires_at' => $expiry]);
        }
    }

    protected function expiry()
    {
        return now()->addDays((int) config('workshop.customer_channel.link_valid_days', 30));
    }
}
