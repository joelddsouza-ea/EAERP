<?php

namespace App\Modules\Workshop\Services;

use App\Core\Models\User;
use App\Modules\Workshop\Models\CustomerLink;
use App\Modules\Workshop\Models\JobCard;

/**
 * One active login-free link per job card. Every message that carries the link
 * extends it, so it stays valid for link_valid_days after the last message
 * (the thank-you message at delivery).
 *
 * A fleet cab's drivers get links of their own (fleet_driver_id set): the same page, but
 * their approvals are held to the fleet's limit. Without a driver id, everything here is
 * about the customer's link.
 */
class CustomerLinkService
{
    public function activeFor(JobCard $jobCard, ?int $driverId = null): ?CustomerLink
    {
        return CustomerLink::where('job_card_id', $jobCard->id)
            ->where(fn ($q) => $driverId ? $q->where('fleet_driver_id', $driverId) : $q->whereNull('fleet_driver_id'))
            ->whereNull('revoked_at')
            ->latest('id')
            ->first();
    }

    /**
     * The usable link for the job, created (or renewed) when needed.
     */
    public function ensure(JobCard $jobCard, ?User $user = null, ?int $driverId = null): CustomerLink
    {
        $link = $this->activeFor($jobCard, $driverId);

        if (!$link) {
            return $this->create($jobCard, $user, $driverId);
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
            ->whereNull('fleet_driver_id')
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

    protected function create(JobCard $jobCard, ?User $user, ?int $driverId = null): CustomerLink
    {
        return CustomerLink::create([
            'job_card_id' => $jobCard->id,
            'fleet_driver_id' => $driverId,
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
