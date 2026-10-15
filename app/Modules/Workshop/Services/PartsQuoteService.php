<?php

namespace App\Modules\Workshop\Services;

use App\Core\Models\Role;
use App\Core\Models\User;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobCardAdditionalJob;
use App\Modules\Workshop\Models\JobCardEstimate;
use App\Modules\Workshop\Models\JobCardIssue;
use App\Modules\Workshop\Models\JobCardEstimateLine as Line;
use App\Modules\Workshop\Models\SparePartQuote;
use App\Modules\Workshop\Models\SparePartRequest;
use App\Modules\Workshop\Models\Supplier;
use App\Modules\Workshop\Notifications\StaffAlert;
use App\Core\Support\WorkshopSettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Parts pricing loop between the advisor and the spare parts coordinator:
 * price request → supplier quotes → chosen quote → estimate line with margin →
 * customer decision → procurement.
 */
class PartsQuoteService
{
    /**
     * Advisor asks the coordinator for prices.
     *
     * @param array<int, array{part_name:string, part_number?:?string, quantity?:float, unit?:?string, note?:?string}> $parts
     * @return Collection<int, SparePartRequest>
     */
    public function requestPrices(JobCard $jobCard, array $parts, User $advisor): Collection
    {
        if ($jobCard->isClosed()) {
            throw new EstimateException('This job card is closed.');
        }

        $requests = DB::transaction(fn () => collect($parts)->map(fn (array $part) => $jobCard->sparePartRequests()->create([
            'part_name' => $part['part_name'],
            'part_number' => $part['part_number'] ?? null,
            'quantity' => $part['quantity'] ?? 1,
            'unit' => $part['unit'] ?? 'nos',
            'requested_note' => $part['note'] ?? null,
            'status' => SparePartRequest::REQUESTED,
            'requested_by' => $advisor->id,
            'requested_at' => now(),
        ])));

        $this->notifyCoordinators($jobCard, StaffAlert::partsPriceRequested(
            $jobCard,
            $requests->pluck('part_name')->all(),
            $advisor->name,
        ));

        return $requests;
    }

    /**
     * Technician lists a part needed for an additional job (stays a draft until submitted to the advisor).
     */
    public function addTechnicianPart(JobCard $jobCard, JobCardAdditionalJob $job, array $part, User $technician): SparePartRequest
    {
        if ($job->job_card_id !== $jobCard->id) {
            throw new EstimateException('That job belongs to another job card.', [], 404);
        }
        if ($jobCard->isClosed()) {
            throw new EstimateException('This job card is closed.');
        }

        return $jobCard->sparePartRequests()->create([
            'job_card_additional_job_id' => $job->id,
            'part_name' => $part['part_name'],
            'part_number' => $part['part_number'] ?? null,
            'quantity' => $part['quantity'] ?? 1,
            'unit' => $part['unit'] ?? 'nos',
            'requested_note' => $part['note'] ?? null,
            'status' => SparePartRequest::DRAFT,
            'requested_by' => $technician->id,
            'requested_at' => now(),
        ]);
    }

    /**
     * Technician lists a part required for an Additional Issues line (draft until submitted to the advisor).
     */
    public function addIssuePart(JobCard $jobCard, JobCardIssue $issue, array $part, User $technician): SparePartRequest
    {
        if ($issue->job_card_id !== $jobCard->id) {
            throw new EstimateException('That line belongs to another job card.', [], 404);
        }
        if ($jobCard->isClosed()) {
            throw new EstimateException('This job card is closed.');
        }
        if (in_array($issue->status, ['resolved', 'deferred'], true)) {
            throw new EstimateException('Re-open the line to add parts to it.');
        }
        // Evidence of the faulty part first, then the part required
        if (!app(IssueLineRules::class)->canListParts($issue->load(IssueLineRules::RELATIONS))) {
            throw new EstimateException('Add a photo or video of the faulty part first.', [
                'part_name' => ['Add a photo or video of the faulty part first.'],
            ]);
        }

        return $jobCard->sparePartRequests()->create([
            'job_card_issue_id' => $issue->id,
            'part_name' => $part['part_name'],
            'part_number' => $part['part_number'] ?? null,
            'quantity' => $part['quantity'] ?? 1,
            'unit' => $part['unit'] ?? 'nos',
            'requested_note' => $part['note'] ?? null,
            'status' => SparePartRequest::DRAFT,
            'requested_by' => $technician->id,
            'requested_at' => now(),
        ]);
    }

    /**
     * "Submit to SA for Parts": the technician's draft parts go to the job's advisor.
     *
     * @return Collection<int, SparePartRequest> the parts sent
     */
    public function submitToAdvisor(JobCard $jobCard, User $technician): Collection
    {
        $drafts = $jobCard->sparePartRequests()->where('status', SparePartRequest::DRAFT)->get();
        if ($drafts->isEmpty()) {
            throw new EstimateException('Add the parts needed to an additional job first.', [
                'parts' => ['No parts listed yet.'],
            ]);
        }

        SparePartRequest::whereIn('id', $drafts->pluck('id'))->update(['status' => SparePartRequest::PROPOSED]);

        $advisor = $jobCard->service_advisor_id ? User::find($jobCard->service_advisor_id) : null;
        $advisor?->notify(StaffAlert::technicianNeedsParts($jobCard, $technician->name, $drafts->pluck('part_name')->all()));

        return $drafts;
    }

    /**
     * Advisor sends parts the technician proposed to the spare parts coordinator for prices.
     *
     * @param int[] $ids
     */
    public function forwardToCoordinator(JobCard $jobCard, array $ids, User $advisor): Collection
    {
        $requests = $jobCard->sparePartRequests()
            ->whereIn('id', $ids)
            ->whereIn('status', [SparePartRequest::PROPOSED, SparePartRequest::DRAFT])
            ->get();

        if ($requests->isEmpty()) {
            throw new EstimateException('Choose parts the technician has sent you.');
        }

        SparePartRequest::whereIn('id', $requests->pluck('id'))->update([
            'status' => SparePartRequest::REQUESTED,
            'forwarded_by' => $advisor->id,
            'forwarded_at' => now(),
        ]);

        $this->notifyCoordinators($jobCard, StaffAlert::partsPriceRequested(
            $jobCard,
            $requests->pluck('part_name')->all(),
            $advisor->name,
        ));

        return $requests;
    }

    /**
     * Coordinator marks a part as received (until the Phase 3 GRN does this); the job's
     * technician and advisor are told it has arrived.
     */
    public function markReceived(SparePartRequest $request, User $user): SparePartRequest
    {
        if (!in_array($request->status, [SparePartRequest::APPROVED, SparePartRequest::ORDERED], true)) {
            throw new EstimateException('Only approved or ordered parts can be marked received.');
        }

        $request->update([
            'status' => SparePartRequest::RECEIVED,
            'received_by' => $user->id,
            'received_at' => now(),
            'received_quantity' => $request->quantity,
            'ordered_by' => $request->ordered_by ?? $user->id,
            'ordered_at' => $request->ordered_at ?? now(),
        ]);

        $jobCard = $request->jobCard()->withoutGlobalScopes()->first();
        if ($jobCard) {
            // Every technician on the job (lead and co-technicians)
            $technicianIds = $jobCard->technicians()->pluck('users.id')->push($jobCard->technician_id)->filter()->unique();
            foreach (User::whereIn('id', $technicianIds)->get() as $technician) {
                $technician->notify(StaffAlert::partsReceived($jobCard, [$request->part_name], true));
            }
            if ($jobCard->service_advisor_id && !$technicianIds->contains($jobCard->service_advisor_id)) {
                User::find($jobCard->service_advisor_id)?->notify(StaffAlert::partsReceived($jobCard, [$request->part_name], false));
            }

            // Last part in: Awaiting Parts → back to work
            app(JobLifecycleService::class)->sync($jobCard, 'parts_received');
        }

        return $request;
    }

    /**
     * Coordinator records a supplier's price. A new supplier can be named inline.
     */
    public function addQuote(SparePartRequest $request, array $data, User $user): SparePartQuote
    {
        if (!in_array($request->status, SparePartRequest::QUOTABLE, true)) {
            throw new EstimateException('This part has already gone to the customer; quotes can no longer change.');
        }

        $supplierId = $data['supplier_id'] ?? null;
        if (!$supplierId) {
            $supplierId = Supplier::firstOrCreate(
                ['name' => trim($data['supplier_name'])],
                ['phone' => $data['supplier_phone'] ?? null, 'is_active' => true],
            )->id;
        }

        $gstRate = (float) ($data['gst_rate'] ?? 18);

        return $request->quotes()->create([
            'supplier_id' => $supplierId,
            'unit_cost' => $data['unit_cost'],
            'gst_mode' => $data['gst_mode'],
            'gst_rate' => $gstRate,
            'landed_unit_cost' => SparePartQuote::landedCost((float) $data['unit_cost'], $data['gst_mode'], $gstRate),
            'availability' => $data['availability'] ?? null,
            'created_by' => $user->id,
        ])->load('supplier');
    }

    public function removeQuote(SparePartRequest $request, SparePartQuote $quote): void
    {
        if (!in_array($request->status, SparePartRequest::QUOTABLE, true)) {
            throw new EstimateException('This part has already gone to the customer; quotes can no longer change.');
        }

        DB::transaction(function () use ($request, $quote) {
            if ($request->selected_quote_id === $quote->id) {
                $request->update(['selected_quote_id' => null, 'status' => SparePartRequest::REQUESTED]);
            }
            $quote->delete();
        });
    }

    /**
     * Coordinator submits chosen quotes; each requesting advisor is notified once per job card.
     *
     * @param array<int, int> $selections spare_part_request_id => spare_part_quote_id
     * @return Collection<int, SparePartRequest>
     */
    public function submitQuotes(array $selections, User $user): Collection
    {
        $requests = SparePartRequest::with('jobCard')->whereIn('id', array_keys($selections))->get();

        DB::transaction(function () use ($requests, $selections, $user) {
            foreach ($requests as $request) {
                if (!in_array($request->status, SparePartRequest::QUOTABLE, true)) {
                    throw new EstimateException("{$request->part_name} has already gone to the customer.");
                }
                $quoteId = (int) $selections[$request->id];
                if (!$request->quotes()->whereKey($quoteId)->exists()) {
                    throw new EstimateException("Pick one of the quotes entered for {$request->part_name}.");
                }
                $request->update([
                    'selected_quote_id' => $quoteId,
                    'status' => SparePartRequest::QUOTED,
                    'quoted_by' => $user->id,
                    'quoted_at' => now(),
                ]);
            }
        });

        // Prices are for the advisor (and owner / managers) — not the technician who listed the parts
        foreach ($requests->groupBy('job_card_id') as $group) {
            $jobCard = $group->first()->jobCard;
            $recipients = $this->advisorsAndManagers($jobCard, $group->pluck('forwarded_by')->all());
            Notification::send($recipients, StaffAlert::partsQuoted($jobCard, $group->pluck('part_name')->all()));
        }

        return $requests;
    }

    /**
     * Advisor puts a quoted part on the draft estimate: GST-inclusive cost plus margin.
     */
    public function addToEstimate(JobCardEstimate $estimate, SparePartRequest $request, ?float $marginPct = null): Line
    {
        if ($request->job_card_id !== $estimate->job_card_id) {
            throw new EstimateException('That part request belongs to another job card.', [], 404);
        }
        $request->loadMissing('selectedQuote');
        if ($request->status !== SparePartRequest::QUOTED || !$request->selectedQuote) {
            throw new EstimateException('The spare parts coordinator has not priced this part yet.');
        }
        if ($estimate->lines()->where('spare_part_request_id', $request->id)->exists()) {
            throw new EstimateException('This part is already on the estimate.');
        }

        $margin = $marginPct ?? (float) WorkshopSettings::get('parts.default_margin_pct');
        $cost = (float) $request->selectedQuote->landed_unit_cost;

        return DB::transaction(function () use ($estimate, $request, $margin, $cost) {
            // Resolved here: EstimateService → EstimateDecisionService → this service
            $line = app(EstimateService::class)->addLine($estimate, [
                'kind' => 'part',
                'description' => trim($request->part_name . ($request->part_number ? " ({$request->part_number})" : '')),
                'quantity' => (float) $request->quantity,
                'rate' => EstimateService::priceWithMargin($cost, $margin),
                'unit_cost' => $cost,
                'margin_pct' => $margin,
                'spare_part_request_id' => $request->id,
                // A part the technician asked for belongs to that job sheet line
                'job_card_additional_job_id' => $request->job_card_additional_job_id,
                'job_card_issue_id' => $request->job_card_issue_id,
            ]);

            $request->update(['estimate_line_key' => $line->line_key]);

            return $line;
        });
    }

    /**
     * Mirror the customer's decisions on part lines onto their requests, and tell
     * the coordinators which parts are newly approved for procurement.
     */
    public function syncFromEstimate(JobCard $jobCard, JobCardEstimate $estimate): void
    {
        $newlyApproved = [];

        // Parts the advisor typed straight onto the estimate (not priced through the Parts Desk):
        // once approved they still have to be procured, so they go to the coordinator's list
        $manual = $estimate->lines()
            ->where('kind', 'part')
            ->whereNull('spare_part_request_id')
            ->where('approval_status', Line::APPROVED)
            ->get();
        foreach ($manual as $line) {
            $request = $jobCard->sparePartRequests()->create([
                'estimate_line_key' => $line->line_key,
                'job_card_issue_id' => $line->job_card_issue_id,
                'part_name' => $line->description,
                'quantity' => $line->quantity,
                'unit' => 'nos',
                'status' => SparePartRequest::APPROVED,
                'requested_by' => $line->decided_by ?? $jobCard->service_advisor_id,
                'requested_at' => now(),
                'approved_by' => $line->decided_by,
                'approved_at' => now(),
            ]);
            $line->update(['spare_part_request_id' => $request->id]);
            $newlyApproved[] = $request->part_name;
        }

        $lines = $estimate->lines()->whereNotNull('spare_part_request_id')->get();
        if ($lines->isEmpty()) {
            return;
        }

        $requests = SparePartRequest::whereIn('id', $lines->pluck('spare_part_request_id'))->get()->keyBy('id');

        foreach ($lines as $line) {
            $request = $requests->get($line->spare_part_request_id);
            // Ordered / received parts are past the decision stage
            if (!$request || !in_array($request->status, [SparePartRequest::QUOTED, SparePartRequest::APPROVED, SparePartRequest::DECLINED], true)) {
                continue;
            }

            $status = match ($line->approval_status) {
                Line::APPROVED => SparePartRequest::APPROVED,
                Line::DECLINED => SparePartRequest::DECLINED,
                default => SparePartRequest::QUOTED,
            };

            if ($status === $request->status) {
                continue;
            }
            if ($status === SparePartRequest::APPROVED) {
                $newlyApproved[] = $request->part_name;
            }

            $request->update([
                'status' => $status,
                'approved_by' => $status === SparePartRequest::APPROVED ? $line->decided_by : null,
                'approved_at' => $status === SparePartRequest::APPROVED ? now() : null,
            ]);
        }

        if ($newlyApproved) {
            $this->notifyCoordinators($jobCard, StaffAlert::partsToProcure($jobCard, $newlyApproved));
        }
    }

    public function markOrdered(SparePartRequest $request, User $user): SparePartRequest
    {
        if ($request->status !== SparePartRequest::APPROVED) {
            throw new EstimateException('Only parts the customer approved can be ordered.');
        }

        $request->update(['status' => SparePartRequest::ORDERED, 'ordered_by' => $user->id, 'ordered_at' => now()]);

        return $request;
    }

    public function cancel(SparePartRequest $request): SparePartRequest
    {
        $cancellable = [SparePartRequest::DRAFT, SparePartRequest::PROPOSED, ...SparePartRequest::QUOTABLE];
        if (!in_array($request->status, $cancellable, true) || $request->estimate_line_key) {
            throw new EstimateException('This part is already on an estimate; remove the estimate line instead.');
        }

        $request->update(['status' => SparePartRequest::CANCELLED]);

        return $request;
    }

    /**
     * The job's advisor, whoever forwarded the parts, and the owner / managers of the branch.
     *
     * @param array<int, ?int> $extraUserIds
     */
    protected function advisorsAndManagers(JobCard $jobCard, array $extraUserIds = []): \Illuminate\Support\Collection
    {
        $ids = collect($extraUserIds)->push($jobCard->service_advisor_id)->filter();

        $managers = User::active()
            ->whereHas('roles', fn ($q) => $q->whereIn('slug', [Role::OWNER, Role::MANAGER, Role::BODY_SHOP_MANAGER]))
            ->get()
            // Owners see every branch; managers only their own
            ->filter(fn (User $u) => $u->hasRole(Role::OWNER) || !$jobCard->branch_id || $u->hasAccessToBranch($jobCard->branch_id));

        return User::whereIn('id', $ids)->get()->concat($managers)->unique('id')->values();
    }

    protected function notifyCoordinators(JobCard $jobCard, StaffAlert $alert): void
    {
        $coordinators = User::active()
            ->withRole(Role::SPARE_PARTS_COORDINATOR)
            ->when($jobCard->branch_id, fn ($q, $branchId) => $q->forBranch($branchId))
            ->get();

        Notification::send($coordinators, $alert);
    }
}
