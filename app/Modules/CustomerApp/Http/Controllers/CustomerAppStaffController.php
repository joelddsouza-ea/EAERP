<?php

namespace App\Modules\CustomerApp\Http\Controllers;

use App\Core\Http\Controllers\Api\ApiController;
use App\Core\Support\WorkshopSettings;
use App\Modules\CustomerApp\Models\AiRequest;
use App\Modules\CustomerApp\Models\CustomerDocument;
use App\Modules\CustomerApp\Models\PaymentClaim;
use App\Modules\CustomerApp\Services\Ai\AiAssistant;
use App\Modules\CustomerApp\Services\Ai\AiSettings;
use App\Modules\CustomerApp\Services\CustomerAppException;
use App\Modules\CustomerApp\Services\CustomerAuthService;
use App\Modules\CustomerApp\Services\CustomerExtrasService;
use App\Modules\CustomerApp\Services\PaymentClaimService;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\JobCard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Staff side of the customer app: the sign-in code to read out, UPI payments to confirm,
 * the customer's documents, and Settings › AI assistant.
 */
class CustomerAppStaffController extends ApiController
{
    public function signInCode(Customer $customer, CustomerAuthService $auth): JsonResponse
    {
        return $this->success([
            'code' => $auth->openCodeFor($customer),
            'sessions' => \App\Modules\CustomerApp\Models\CustomerSession::where('customer_id', $customer->id)->whereNull('revoked_at')->where('expires_at', '>', now())->count(),
        ]);
    }

    public function customerDocuments(Customer $customer, CustomerExtrasService $extras): JsonResponse
    {
        return $this->success($extras->documents($customer));
    }

    // ------------------------------------------------------------------ UPI claims

    public function claims(Request $request, PaymentClaimService $claims): JsonResponse
    {
        $user = $request->user();
        $status = $request->query('status', PaymentClaim::PENDING);
        $rows = PaymentClaim::query()
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when(!$user->isOwner(), fn ($q) => $q->whereIn('branch_id', $user->getAccessibleBranchIds()))
            ->latest('id')->limit(200)->get();

        return $this->success([
            'rows' => $rows->map(fn ($c) => $claims->present($c, true))->values(),
            'pending' => PaymentClaim::where('status', PaymentClaim::PENDING)
                ->when(!$user->isOwner(), fn ($q) => $q->whereIn('branch_id', $user->getAccessibleBranchIds()))->count(),
        ]);
    }

    public function jobClaims(JobCard $jobCard, PaymentClaimService $claims): JsonResponse
    {
        return $this->success(PaymentClaim::where('job_card_id', $jobCard->id)->latest('id')->get()->map(fn ($c) => $claims->present($c, true))->values());
    }

    public function confirm(Request $request, PaymentClaim $claim, PaymentClaimService $claims): JsonResponse
    {
        $this->assertBranch($request, $claim);
        $amount = $request->validate(['amount' => 'nullable|numeric|min:1'])['amount'] ?? null;

        try {
            return $this->success($claims->present($claims->confirm($claim, $request->user(), $amount !== null ? (float) $amount : null), true), 'Payment recorded');
        } catch (CustomerAppException $e) {
            return $this->error($e->getMessage(), $e->status, $e->errors);
        } catch (\App\Modules\Billing\Services\BillingException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function reject(Request $request, PaymentClaim $claim, PaymentClaimService $claims): JsonResponse
    {
        $this->assertBranch($request, $claim);
        $reason = (string) $request->validate(['reason' => 'required|string|max:255'])['reason'];

        try {
            return $this->success($claims->present($claims->reject($claim, $request->user(), $reason), true), 'Rejected; the customer is told');
        } catch (CustomerAppException $e) {
            return $this->error($e->getMessage(), $e->status, $e->errors);
        }
    }

    protected function assertBranch(Request $request, PaymentClaim $claim): void
    {
        $user = $request->user();
        abort_if(!$user->isOwner() && $claim->branch_id && !in_array($claim->branch_id, $user->getAccessibleBranchIds()), 403);
    }

    // ------------------------------------------------------------------ files (signed links)

    public function documentFile(CustomerDocument $document)
    {
        abort_unless(Storage::disk($document->file_disk)->exists($document->file_path), 404);

        return Storage::disk($document->file_disk)->response($document->file_path, null, ['Cache-Control' => 'private, max-age=600']);
    }

    public function claimScreenshot(PaymentClaim $claim)
    {
        abort_unless($claim->screenshot_path && Storage::disk($claim->screenshot_disk)->exists($claim->screenshot_path), 404);

        return Storage::disk($claim->screenshot_disk)->response($claim->screenshot_path);
    }

    // ------------------------------------------------------------------ Settings › AI assistant (owner)

    public function aiSettings(AiAssistant $ai): JsonResponse
    {
        return $this->success($this->aiState($ai));
    }

    public function saveAiSettings(Request $request, AiAssistant $ai): JsonResponse
    {
        $data = $request->validate([
            'enabled' => 'boolean',
            'api_key' => 'nullable|string|max:300',
            'clear_api_key' => 'boolean',
            'model' => 'nullable|in:' . implode(',', array_keys(AiAssistant::MODELS)),
            'daily_limit' => 'nullable|integer|min:1|max:100',
            'monthly_budget_inr' => 'nullable|numeric|min:0|max:10000000',
            'usd_inr' => 'nullable|numeric|min:1|max:500',
            'body_shop_guide' => 'nullable|string|max:5000',
        ]);

        if (!empty($data['clear_api_key'])) {
            AiSettings::setApiKey(null);
        } elseif (!empty($data['api_key'])) {
            AiSettings::setApiKey($data['api_key']);
        }
        if (!empty($data['enabled']) && AiSettings::apiKey() === null) {
            return $this->error('Enter the Anthropic API key before switching the assistant on.', 422, ['api_key' => ['Required.']]);
        }
        foreach (['enabled', 'model', 'daily_limit', 'monthly_budget_inr', 'usd_inr', 'body_shop_guide'] as $key) {
            if (array_key_exists($key, $data)) {
                WorkshopSettings::set("ai.{$key}", $data[$key]);
            }
        }

        return $this->success($this->aiState($ai), 'Saved');
    }

    protected function aiState(AiAssistant $ai): array
    {
        $month = AiRequest::where('created_at', '>=', now()->startOfMonth());

        return [
            'enabled' => (bool) WorkshopSettings::get('ai.enabled'),
            'api_key_masked' => AiSettings::maskedKey(),
            'model' => AiSettings::model(),
            'models' => collect(AiAssistant::MODELS)->map(fn ($label, $id) => ['id' => $id, 'label' => $label])->values(),
            'daily_limit' => (int) WorkshopSettings::get('ai.daily_limit'),
            'monthly_budget_inr' => (float) WorkshopSettings::get('ai.monthly_budget_inr'),
            'usd_inr' => (float) WorkshopSettings::get('ai.usd_inr'),
            'body_shop_guide' => (string) WorkshopSettings::get('ai.body_shop_guide'),
            'usage' => [
                'month_cost_inr' => $ai->monthCost(),
                'month_requests' => (clone $month)->count(),
                'by_feature' => (clone $month)->selectRaw('feature, COUNT(*) n, SUM(cost_inr) cost')->groupBy('feature')->get()
                    ->map(fn ($r) => ['feature' => $r->feature, 'count' => (int) $r->n, 'cost_inr' => round((float) $r->cost, 2)])->values(),
                'booked' => (clone $month)->where('outcome', 'booked')->count(),
                'leads' => (clone $month)->where('outcome', 'lead')->count(),
                'recent' => AiRequest::with('customer:id,name')->latest('id')->limit(20)->get()->map(fn (AiRequest $r) => [
                    'id' => $r->id,
                    'customer' => $r->customer?->name,
                    'feature' => $r->feature,
                    'question' => mb_strimwidth((string) $r->question, 0, 140, '…'),
                    'summary' => $r->answer['summary'] ?? $r->answer['explanation'] ?? null,
                    'urgency' => $r->answer['urgency'] ?? null,
                    'cost_inr' => (float) $r->cost_inr,
                    'error' => $r->error,
                    'outcome' => $r->outcome,
                    'created_at' => $r->created_at?->toIso8601String(),
                ])->values(),
            ],
        ];
    }
}
