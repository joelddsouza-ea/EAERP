<?php

namespace App\Modules\Workshop\Http\Controllers;

use App\Core\Http\Controllers\Api\ApiController;
use App\Modules\Workshop\Http\Resources\JobCardMediaResource;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobCardEstimate;
use App\Modules\Workshop\Models\JobCardEstimateComment;
use App\Modules\Workshop\Models\JobCardEstimateDecision;
use App\Modules\Workshop\Models\JobCardEstimateLine as Line;
use App\Modules\Workshop\Models\JobCardEstimateLine;
use App\Modules\Workshop\Models\OilType;
use App\Modules\Workshop\Models\ServiceAddon;
use App\Modules\Workshop\Models\ServicePackage;
use App\Modules\Workshop\Services\CustomerDocumentService;
use App\Modules\Workshop\Services\CustomerMessageService;
use App\Modules\Workshop\Services\EstimateDecisionService;
use App\Modules\Workshop\Services\EstimatePresenter;
use App\Modules\Workshop\Services\EstimateService;
use App\Modules\Workshop\Services\LabourRateResolver;
use App\Modules\Workshop\Services\PartsQuoteService;
use App\Modules\Workshop\Services\ObservationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Advisor side of estimates: build and edit drafts, send to the customer, record
 * decisions on the customer's behalf, and the decision audit log.
 */
class EstimateController extends ApiController
{
    public function __construct(
        protected EstimateService $estimates,
        protected EstimateDecisionService $decisions,
        protected EstimatePresenter $presenter,
        protected CustomerMessageService $messages,
        protected PartsQuoteService $parts,
        protected ObservationService $observations,
    ) {}

    /**
     * All versions (newest first), with the current draft and the version the customer sees.
     */
    public function index(JobCard $jobCard): JsonResponse
    {
        $versions = $jobCard->estimates()->with('sentBy:id,name')->get();
        $draft = $versions->firstWhere('status', JobCardEstimate::STATUS_DRAFT);
        $sent = $versions->firstWhere('status', JobCardEstimate::STATUS_SENT);

        return $this->success([
            'versions' => $versions->map(fn (JobCardEstimate $e) => [
                'id' => $e->id,
                'version' => $e->version,
                'status' => $e->status,
                'grand_total' => (float) $e->grand_total,
                'sent_at' => $e->sent_at?->toIso8601String(),
                'sent_by' => $e->sentBy?->name,
                'responded_at' => $e->responded_at?->toIso8601String(),
            ])->values(),
            'draft' => $draft ? $this->presenter->present($draft) : null,
            'current' => $sent ? $this->presenter->present($sent) : null,
            'decisions_open' => $this->decisions->decisionsOpen($jobCard),
        ]);
    }

    public function show(JobCard $jobCard, JobCardEstimate $estimate): JsonResponse
    {
        return $this->success($this->presenter->present($estimate));
    }

    /**
     * Open a draft to edit: the existing draft, a new version of the sent one, or a first estimate.
     */
    public function draft(Request $request, JobCard $jobCard): JsonResponse
    {
        $estimate = $this->estimates->draft($jobCard, $request->user());

        return $this->success($this->presenter->present($estimate->fresh()), 'Draft ready');
    }

    public function update(Request $request, JobCard $jobCard, JobCardEstimate $estimate): JsonResponse
    {
        $data = $request->validate([
            'package_id' => ['sometimes', 'nullable', Rule::exists(ServicePackage::class, 'id')],
            'oil_type_id' => ['sometimes', 'nullable', Rule::exists(OilType::class, 'id')],
            'plan_discount_pct' => 'sometimes|numeric|min:0|max:100',
            'plan_discount_flat' => 'sometimes|numeric|min:0',
            'extras_discount_pct' => 'sometimes|numeric|min:0|max:100',
            'extras_discount_flat' => 'sometimes|numeric|min:0',
            'next_service_km' => 'sometimes|nullable|integer|min:0',
            'next_service_date' => 'sometimes|nullable|date',
            'payment_mode' => 'sometimes|nullable|string|max:30',
            'notes' => 'sometimes|nullable|string|max:2000',
        ]);

        $estimate = $this->estimates->updateHeader($estimate, $data);

        return $this->success($this->presenter->present($estimate->fresh()), 'Estimate updated');
    }

    public function refreshPlan(JobCard $jobCard, JobCardEstimate $estimate): JsonResponse
    {
        $estimate = $this->estimates->refreshPlan($estimate);

        return $this->success($this->presenter->present($estimate->fresh()), 'Plan prices refreshed');
    }

    public function storeLine(Request $request, JobCard $jobCard, JobCardEstimate $estimate): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in([Line::KIND_PLAN_EXTRA, ...Line::ADDITIONAL_KINDS])],
            'description' => 'required|string|max:255',
            'quantity' => 'sometimes|numeric|min:0',
            'rate' => 'required|numeric',
            'media_ids' => 'sometimes|array',
            'media_ids.*' => 'integer',
            'job_card_additional_job_id' => ['sometimes', 'nullable', Rule::exists('job_card_additional_jobs', 'id')->where('job_card_id', $jobCard->id)],
            'job_card_issue_id' => ['sometimes', 'nullable', Rule::exists('job_card_issues', 'id')->where('job_card_id', $jobCard->id)],
            'labour_service_id' => ['sometimes', 'nullable', Rule::exists('labour_services', 'id')],
        ]);

        $this->estimates->addLine($estimate, $data);

        return $this->created($this->presenter->present($estimate->fresh()), 'Line added');
    }

    public function updateLine(Request $request, JobCard $jobCard, JobCardEstimate $estimate, JobCardEstimateLine $line): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['sometimes', Rule::in(Line::ADDITIONAL_KINDS)],
            'description' => 'sometimes|required|string|max:255',
            'quantity' => 'sometimes|numeric|min:0',
            'rate' => 'sometimes|numeric',
            'margin_pct' => 'sometimes|nullable|numeric|min:0|max:500',
            'media_ids' => 'sometimes|array',
            'media_ids.*' => 'integer',
        ]);

        if (isset($data['kind']) && !$line->isAdditional()) {
            unset($data['kind']);
        }

        $this->estimates->updateLine($estimate, $line, $data);

        return $this->success($this->presenter->present($estimate->fresh()), 'Line updated');
    }

    public function destroyLine(JobCard $jobCard, JobCardEstimate $estimate, JobCardEstimateLine $line): JsonResponse
    {
        $this->estimates->removeLine($estimate, $line);

        return $this->success($this->presenter->present($estimate->fresh()), 'Line removed');
    }

    public function send(Request $request, JobCard $jobCard, JobCardEstimate $estimate): JsonResponse
    {
        $result = $this->estimates->send($estimate, $request->user());
        // A part line changed in this version goes back to waiting for the customer
        $this->parts->syncFromEstimate($jobCard, $result['estimate']);
        $this->observations->syncDeclinedLines($jobCard, $result['estimate']);

        return $this->success([
            'estimate' => $this->presenter->present($result['estimate']),
            'message' => $this->messages->present($result['message']),
            'moved_to' => $result['moved_to'],
        ], 'Estimate sent');
    }

    /**
     * Record approvals the customer gave by phone or in person. Always logged with a
     * note, and the customer is sent a message listing what was recorded.
     */
    public function decide(Request $request, JobCard $jobCard): JsonResponse
    {
        $data = $request->validate([
            'decisions' => 'required|array|min:1',
            'decisions.*.line_key' => 'required|uuid',
            'decisions.*.decision' => ['required', Rule::in([Line::APPROVED, Line::DECLINED])],
            'note' => 'required|string|max:1000',
        ]);

        $changes = $this->decisions->decide(
            $jobCard,
            collect($data['decisions'])->pluck('decision', 'line_key')->all(),
            Line::VIA_ADVISOR,
            $request->user(),
            $data['note'],
            $request->ip(),
        );

        $message = $changes
            ? $jobCard->customerMessages()->where('template', 'decision_recorded')->first()
            : null;

        return $this->success([
            'changes' => $changes,
            'estimate' => $this->presenter->present($this->decisions->currentEstimate($jobCard->fresh())),
            'message' => $message ? $this->messages->present($message) : null,
            'job_status' => $jobCard->fresh()->status,
        ], $changes ? 'Decisions recorded; the customer has a message to send' : 'Nothing changed');
    }

    public function comment(Request $request, JobCard $jobCard, JobCardEstimate $estimate): JsonResponse
    {
        $data = $request->validate(['body' => 'required|string|max:2000']);

        $estimate->comments()->create([
            'author_type' => JobCardEstimateComment::AUTHOR_STAFF,
            'user_id' => $request->user()->id,
            'body' => $data['body'],
        ]);

        return $this->created($this->presenter->present($estimate->fresh()), 'Comment added');
    }

    /**
     * Audit log of every approval decision on the job card.
     */
    public function decisionLog(JobCard $jobCard): JsonResponse
    {
        $rows = JobCardEstimateDecision::where('job_card_id', $jobCard->id)
            ->with(['user:id,name', 'customerMessage:id,status,sent_at'])
            ->latest('id')
            ->get();

        return $this->success($rows);
    }

    /**
     * Photos and videos of the job, to attach to estimate lines or share with the customer.
     */
    public function media(JobCard $jobCard): JsonResponse
    {
        return $this->success(JobCardMediaResource::collection($jobCard->media()->orderBy('id')->get()));
    }

    public function pdf(JobCard $jobCard, JobCardEstimate $estimate, CustomerDocumentService $documents)
    {
        return $documents->estimatePdf($jobCard, $estimate);
    }

    /**
     * Description and price suggestions for additional lines: the labour charges
     * master for this job's car first, then earlier lines for the same car type
     * (last and median rate), then the service add-on list.
     */
    public function suggestions(Request $request, LabourRateResolver $labour): JsonResponse
    {
        $data = $request->validate([
            'q' => 'required|string|min:2|max:100',
            'category_id' => 'nullable|integer',
            'job_card_id' => 'nullable|integer',
        ]);

        $jobCard = isset($data['job_card_id']) ? JobCard::find($data['job_card_id']) : null;
        $labourRows = $jobCard
            ? $labour->forJobCard($jobCard, $data['q'])->take(8)->map(fn (array $s) => [
                'description' => $s['description'],
                'kind' => 'labor',
                'last_rate' => $s['rate'],
                'median_rate' => $s['rate'],
                'times_used' => 0,
                'source' => 'labour',
                'labour_service_id' => $s['labour_service_id'],
                'unit' => $s['unit'],
            ])
            : collect();

        $like = '%' . addcslashes($data['q'], '%_\\') . '%';

        $history = DB::table('job_card_estimate_lines as l')
            ->join('job_card_estimates as e', 'e.id', '=', 'l.estimate_id')
            ->where('l.section', Line::SECTION_ADDITIONAL)
            ->where('e.status', '!=', JobCardEstimate::STATUS_DRAFT)
            ->where('l.description', 'like', $like)
            ->when($data['category_id'] ?? null, fn ($q, $c) => $q->where('e.category_id', $c))
            ->orderByDesc('l.id')
            ->limit(200)
            ->get(['l.description', 'l.kind', 'l.rate'])
            ->groupBy(fn ($r) => mb_strtolower(trim($r->description)))
            ->map(function ($rows) {
                $rates = $rows->pluck('rate')->map(fn ($r) => (float) $r)->sort()->values();

                return [
                    'description' => $rows->first()->description,
                    'kind' => $rows->first()->kind,
                    'last_rate' => (float) $rows->first()->rate,
                    'median_rate' => $rates[intdiv($rates->count(), 2)],
                    'times_used' => $rows->count(),
                    'source' => 'history',
                ];
            })
            ->sortByDesc('times_used')
            ->take(8)
            ->values();

        $addons = ServiceAddon::where('is_active', true)
            ->where('name', 'like', $like)
            ->limit(5)
            ->get(['name', 'price'])
            ->map(fn ($a) => [
                'description' => $a->name,
                'kind' => 'labor',
                'last_rate' => (float) $a->price,
                'median_rate' => (float) $a->price,
                'times_used' => 0,
                'source' => 'addon',
            ]);

        return $this->success($labourRows->concat($history)->concat($addons)->values());
    }
}
