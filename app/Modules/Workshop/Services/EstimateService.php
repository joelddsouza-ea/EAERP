<?php

namespace App\Modules\Workshop\Services;

use App\Core\Models\User;
use App\Modules\Workshop\Enums\JobCardStatus;
use App\Modules\Workshop\Models\CustomerMessage;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobCardEstimate;
use App\Modules\Workshop\Models\JobCardEstimateLine as Line;
use App\Modules\Workshop\Models\OilType;
use App\Modules\Workshop\Models\PackageOilLitre;
use App\Modules\Workshop\Models\PackagePricing;
use App\Modules\Workshop\Models\SparePartRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Builds, edits and sends versioned estimates.
 *
 * Only a draft can be edited. Editing after sending starts a new draft version
 * that copies the lines (same line_key) and their decisions; a line whose
 * description or amount changes goes back to pending.
 */
class EstimateService
{
    private const SORT_OIL = 10;
    private const SORT_ITEMS = 20;
    private const SORT_PLAN_EXTRA = 100;
    private const SORT_LABOUR = 900;
    private const SORT_ADDITIONAL = 1000;

    public function __construct(
        protected EstimateCalculator $calculator,
        protected EstimateDecisionService $decisions,
        protected CustomerMessageService $messages,
        protected CustomerLinkService $links,
        protected JobCardStatusService $statusService,
        protected JobSheetService $jobSheets,
    ) {}

    /**
     * The draft to edit: the existing draft, a new version of the sent estimate,
     * or a first estimate built from the price masters.
     */
    public function draft(JobCard $jobCard, User $user): JobCardEstimate
    {
        $this->assertOpen($jobCard);

        $latest = JobCardEstimate::where('job_card_id', $jobCard->id)->latest('version')->first();

        if ($latest?->isDraft()) {
            return $latest;
        }

        return DB::transaction(fn () => $latest
            ? $this->newVersionFrom($latest, $user)
            : $this->buildFirst($jobCard, $user));
    }

    public function updateHeader(JobCardEstimate $estimate, array $data): JobCardEstimate
    {
        $this->assertDraft($estimate);

        return DB::transaction(function () use ($estimate, $data) {
            $jobCard = $estimate->jobCard;
            $planChanged = false;

            foreach (['package_id', 'oil_type_id'] as $field) {
                if (array_key_exists($field, $data) && (int) $data[$field] !== (int) $jobCard->{$field}) {
                    $jobCard->{$field} = $data[$field];
                    $planChanged = true;
                }
                unset($data[$field]);
            }

            if ($planChanged) {
                $jobCard->save();
                $this->applyPlan($estimate, $jobCard->fresh());
                // A different plan brings its own job sheet (untouched old sheets are dropped)
                $this->jobSheets->sync($jobCard->fresh());
            }

            $estimate->update($data);

            return $this->calculator->recalculate($estimate);
        });
    }

    public function addLine(JobCardEstimate $estimate, array $data): Line
    {
        $this->assertDraft($estimate);

        return DB::transaction(function () use ($estimate, $data) {
            $isPlanExtra = $data['kind'] === Line::KIND_PLAN_EXTRA;
            $section = $isPlanExtra ? Line::SECTION_PLAN : Line::SECTION_ADDITIONAL;
            $base = $isPlanExtra ? self::SORT_PLAN_EXTRA : self::SORT_ADDITIONAL;

            $line = $estimate->lines()->create([
                'line_key' => (string) Str::uuid(),
                'section' => $section,
                'kind' => $data['kind'],
                'description' => $data['description'],
                'quantity' => $data['quantity'] ?? 1,
                'rate' => $data['rate'] ?? 0,
                'gst_inclusive' => $data['gst_inclusive'] ?? true,
                'tax_rate' => $data['tax_rate'] ?? 18,
                'sort_order' => max($base, (int) $estimate->lines()->where('section', $section)->where('kind', '!=', Line::KIND_LABOUR_BALANCE)->max('sort_order')) + 1,
                'approval_status' => $isPlanExtra ? Line::INCLUDED : Line::PENDING,
                'job_card_additional_job_id' => $data['job_card_additional_job_id'] ?? null,
                'job_card_issue_id' => $data['job_card_issue_id'] ?? null,
                'labour_service_id' => $data['labour_service_id'] ?? null,
                'spare_part_request_id' => $data['spare_part_request_id'] ?? null,
                'unit_cost' => $data['unit_cost'] ?? null,
                'margin_pct' => $data['margin_pct'] ?? null,
            ]);

            if (isset($data['media_ids'])) {
                $this->syncMedia($estimate, $line, $data['media_ids']);
            }

            $this->calculator->recalculate($estimate);

            return $line->fresh('media');
        });
    }

    public function updateLine(JobCardEstimate $estimate, Line $line, array $data): Line
    {
        $this->assertDraft($estimate);

        if ($line->kind === Line::KIND_LABOUR_BALANCE) {
            throw new EstimateException('The labour line balances the plan by itself and cannot be edited.');
        }

        // Oil and standard plan items come from the masters: only quantity and rate change
        if (in_array($line->kind, [Line::KIND_OIL, Line::KIND_PLAN_ITEM], true)) {
            $data = array_intersect_key($data, array_flip(['quantity', 'rate']));
        }

        return DB::transaction(function () use ($estimate, $line, $data) {
            $mediaIds = $data['media_ids'] ?? null;
            unset($data['media_ids']);

            $this->applyMargin($line, $data);
            $line->fill($data);
            $amountChanged = $line->customerAmount() !== (float) $line->getOriginal('amount');

            // A decided line that changes must be approved again
            if ($line->isAdditional() && in_array($line->approval_status, [Line::APPROVED, Line::DECLINED], true)
                && ($amountChanged || $line->isDirty('description') || $line->isDirty('kind'))) {
                $line->fill([
                    'approval_status' => Line::PENDING,
                    'decided_via' => null,
                    'decided_by' => null,
                    'decided_at' => null,
                ]);
            }

            $line->save();

            if ($mediaIds !== null) {
                $this->syncMedia($estimate, $line, $mediaIds);
            }

            $this->calculator->recalculate($estimate);

            return $line->fresh('media');
        });
    }

    public function removeLine(JobCardEstimate $estimate, Line $line): void
    {
        $this->assertDraft($estimate);

        if (!in_array($line->kind, [Line::KIND_PLAN_EXTRA, ...Line::ADDITIONAL_KINDS], true)) {
            throw new EstimateException('Plan lines come from the price masters; set the quantity to 0 instead.');
        }

        DB::transaction(function () use ($estimate, $line) {
            // A priced part taken off a draft before the customer saw it can be added again
            if ($line->spare_part_request_id) {
                SparePartRequest::whereKey($line->spare_part_request_id)
                    ->where('status', SparePartRequest::QUOTED)
                    ->update(['estimate_line_key' => null]);
            }

            $line->delete();
            $this->calculator->recalculate($estimate);
        });
    }

    /**
     * Rebuild the plan block from the current masters (e.g. after prices changed).
     */
    public function refreshPlan(JobCardEstimate $estimate): JobCardEstimate
    {
        $this->assertDraft($estimate);

        return DB::transaction(function () use ($estimate) {
            $this->applyPlan($estimate, $estimate->jobCard);

            return $this->calculator->recalculate($estimate);
        });
    }

    /**
     * Send the draft to the customer: it becomes the version they see, the job card
     * mirrors it, a WhatsApp message is prepared, and a job with items to approve
     * moves to Awaiting Approval.
     *
     * @return array{estimate: JobCardEstimate, message: CustomerMessage, moved_to: ?string}
     */
    public function send(JobCardEstimate $estimate, User $user): array
    {
        $this->assertDraft($estimate);
        $jobCard = $estimate->jobCard;
        $this->assertOpen($jobCard);

        $message = DB::transaction(function () use ($estimate, $jobCard, $user) {
            JobCardEstimate::where('job_card_id', $jobCard->id)
                ->where('status', JobCardEstimate::STATUS_SENT)
                ->update(['status' => JobCardEstimate::STATUS_SUPERSEDED]);

            $this->calculator->recalculate($estimate);
            $estimate->update([
                'status' => JobCardEstimate::STATUS_SENT,
                'sent_at' => now(),
                'sent_by' => $user->id,
                'customer_viewed_at' => null,
            ]);

            $estimate->load('lines');
            $this->decisions->syncJobCard($jobCard, $estimate);

            $pending = $estimate->lines->where('approval_status', Line::PENDING)->count();

            return $this->messages->prepare($jobCard, 'estimate_ready', [
                'total' => number_format((float) $estimate->grand_total, 0),
                'pending_text' => $pending
                    ? __('customer_messages.pending_some', ['count' => $pending])
                    : __('customer_messages.pending_none'),
            ], $user);
        });

        return [
            'estimate' => $estimate->fresh('lines'),
            'message' => $message,
            'moved_to' => $this->moveForApproval($jobCard->fresh(), $estimate, $user),
        ];
    }

    /**
     * Items to approve → Awaiting Approval; nothing left to approve → back to work.
     */
    protected function moveForApproval(JobCard $jobCard, JobCardEstimate $estimate, User $user): ?string
    {
        // Moves only into stages set to automatic in the Job stages master
        $moved = app(JobLifecycleService::class)->sync($jobCard, 'estimate_sent');

        return $moved ? $moved[0] : null;
    }

    protected function buildFirst(JobCard $jobCard, User $user): JobCardEstimate
    {
        $estimate = JobCardEstimate::create([
            'job_card_id' => $jobCard->id,
            'version' => 1,
            'status' => JobCardEstimate::STATUS_DRAFT,
            'created_by' => $user->id,
        ]);

        $this->applyPlan($estimate, $jobCard, withNextService: true);

        return $this->calculator->recalculate($estimate);
    }

    protected function newVersionFrom(JobCardEstimate $previous, User $user): JobCardEstimate
    {
        $estimate = $previous->replicate(['sent_at', 'sent_by', 'customer_viewed_at', 'responded_at']);
        $estimate->fill([
            'version' => $previous->version + 1,
            'status' => JobCardEstimate::STATUS_DRAFT,
            'created_by' => $user->id,
        ])->save();

        foreach ($previous->lines()->with('media:id')->get() as $line) {
            $copy = $line->replicate();
            $copy->estimate_id = $estimate->id;
            $copy->save();
            $copy->media()->sync($line->media->pluck('id'));
        }

        return $estimate;
    }

    /**
     * Plan snapshot and plan lines from the masters: oil, the plan's items for the
     * car type (0 = not included), and the labour balance. Extras the advisor added
     * inside the plan are kept.
     */
    protected function applyPlan(JobCardEstimate $estimate, JobCard $jobCard, bool $withNextService = false): void
    {
        $jobCard->loadMissing(['package.lineItems.prices', 'vehicle.category', 'oilType']);

        $package = $jobCard->package;
        $category = $jobCard->vehicle?->category;
        $oilType = $jobCard->oilType ?? $this->defaultOilType($jobCard);

        $price = $package && $category && $oilType
            ? PackagePricing::where([
                'package_id' => $package->id,
                'category_id' => $category->id,
                'oil_type_id' => $oilType->id,
            ])->value('price')
            : null;

        $estimate->fill([
            'package_id' => $package?->id,
            'category_id' => $category?->id,
            'oil_type_id' => $oilType?->id,
            'package_name' => $package?->name,
            'category_name' => $category?->name,
            'oil_type_name' => $oilType?->name,
            'package_price' => $price ?? 0,
        ]);

        if ($withNextService && $package) {
            $estimate->fill([
                'next_service_km' => $package->next_service_km && $jobCard->km_reading
                    ? $jobCard->km_reading + $package->next_service_km
                    : null,
                'next_service_date' => $package->next_service_months ? now()->addMonths($package->next_service_months)->toDateString() : null,
            ]);
        }

        $estimate->save();

        $estimate->lines()
            ->whereIn('kind', [Line::KIND_OIL, Line::KIND_PLAN_ITEM, Line::KIND_LABOUR_BALANCE])
            ->delete();

        if (!$package) {
            return;
        }

        $plan = fn (array $attrs) => $estimate->lines()->create([
            'line_key' => (string) Str::uuid(),
            'section' => Line::SECTION_PLAN,
            'approval_status' => Line::INCLUDED,
            ...$attrs,
        ]);

        $litres = $category
            ? (float) PackageOilLitre::where(['package_id' => $package->id, 'category_id' => $category->id])->value('litres')
            : 0.0;

        if ($oilType && $litres > 0) {
            $plan([
                'kind' => Line::KIND_OIL,
                'description' => $oilType->product_name ?: $oilType->name . ' Engine Oil',
                'quantity' => $litres,
                'rate' => $oilType->rate_per_litre,
                'sort_order' => self::SORT_OIL,
            ]);
        }

        $order = self::SORT_ITEMS;
        foreach ($package->lineItems->where('is_active', true) as $item) {
            $amount = (float) ($item->prices->firstWhere('category_id', $category?->id)?->amount ?? 0);
            if ($amount <= 0) {
                continue;
            }

            $plan([
                'kind' => Line::KIND_PLAN_ITEM,
                'description' => $item->description,
                'quantity' => 1,
                'rate' => $amount,
                'sort_order' => $order++,
            ]);
        }

        $plan([
            'kind' => Line::KIND_LABOUR_BALANCE,
            'description' => $package->name . ' Maintenance Service (Labour)',
            'quantity' => 1,
            'rate' => 0,
            'sort_order' => self::SORT_LABOUR,
        ]);
    }

    /**
     * No oil grade on the job card: the first grade this plan is offered with for the car type.
     */
    protected function defaultOilType(JobCard $jobCard): ?OilType
    {
        if (!$jobCard->package_id || !$jobCard->vehicle?->category_id) {
            return null;
        }

        $oilTypeId = PackagePricing::where('package_id', $jobCard->package_id)
            ->where('category_id', $jobCard->vehicle->category_id)
            ->join('oil_types', 'oil_types.id', '=', 'package_pricing.oil_type_id')
            ->orderBy('oil_types.sort_order')
            ->value('package_pricing.oil_type_id');

        return $oilTypeId ? OilType::find($oilTypeId) : null;
    }

    protected function syncMedia(JobCardEstimate $estimate, Line $line, array $mediaIds): void
    {
        $valid = $estimate->jobCard->media()->whereIn('id', $mediaIds)->pluck('id');

        if ($valid->count() !== count(array_unique($mediaIds))) {
            throw new EstimateException('Some photos or videos do not belong to this job card.');
        }

        $line->media()->sync($valid);
    }

    /**
     * Parts priced from a supplier quote: a new margin % recalculates the rate from the
     * cost, and a typed rate recalculates the margin shown to the advisor.
     */
    protected function applyMargin(Line $line, array &$data): void
    {
        $cost = (float) $line->unit_cost;
        if ($cost <= 0) {
            unset($data['margin_pct']);

            return;
        }

        if (array_key_exists('rate', $data)) {
            $data['margin_pct'] = round(((float) $data['rate'] / $cost - 1) * 100, 2);
        } elseif (array_key_exists('margin_pct', $data) && $data['margin_pct'] !== null) {
            $data['rate'] = self::priceWithMargin($cost, (float) $data['margin_pct']);
        }
    }

    /**
     * Customer price for a part: GST-inclusive cost plus margin, rounded to the rupee.
     */
    public static function priceWithMargin(float $cost, float $marginPct): float
    {
        return round($cost * (1 + $marginPct / 100));
    }

    protected function assertDraft(JobCardEstimate $estimate): void
    {
        if (!$estimate->isDraft()) {
            throw new EstimateException('Only a draft estimate can be changed. Start a new version to edit it.');
        }
    }

    protected function assertOpen(JobCard $jobCard): void
    {
        if ($jobCard->isClosed()) {
            throw new EstimateException('This job card is closed.');
        }
    }
}
