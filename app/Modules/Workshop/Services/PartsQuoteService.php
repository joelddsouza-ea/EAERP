<?php

namespace App\Modules\Workshop\Services;

use App\Core\Models\Role;
use App\Core\Models\User;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobCardEstimate;
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

        foreach ($requests->groupBy(fn ($r) => $r->job_card_id . ':' . $r->requested_by) as $group) {
            $first = $group->first();
            $advisor = User::find($first->requested_by ?? $first->jobCard->service_advisor_id);
            $advisor?->notify(StaffAlert::partsQuoted($first->jobCard, $group->pluck('part_name')->all()));
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
        $lines = $estimate->lines()->whereNotNull('spare_part_request_id')->get();
        if ($lines->isEmpty()) {
            return;
        }

        $requests = SparePartRequest::whereIn('id', $lines->pluck('spare_part_request_id'))->get()->keyBy('id');
        $newlyApproved = [];

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
        if (!in_array($request->status, SparePartRequest::QUOTABLE, true) || $request->estimate_line_key) {
            throw new EstimateException('This part is already on an estimate; remove the estimate line instead.');
        }

        $request->update(['status' => SparePartRequest::CANCELLED]);

        return $request;
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
