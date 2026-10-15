<?php

namespace App\Modules\Workshop\Http\Controllers;

use App\Core\Http\Controllers\Api\ApiController;
use App\Modules\Workshop\Models\CustomerMessage;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobCardEstimateLine;
use App\Modules\Workshop\Models\JobCardMedia;
use App\Modules\Workshop\Services\CustomerLinkService;
use App\Modules\Workshop\Services\CustomerMessageService;
use App\Modules\Workshop\Services\EstimateDecisionService;
use App\Modules\Workshop\Services\EstimateException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The job's customer link and the WhatsApp messages waiting to be sent.
 */
class CustomerChannelController extends ApiController
{
    public function __construct(
        protected CustomerLinkService $links,
        protected CustomerMessageService $messages,
    ) {}

    public function link(JobCard $jobCard): JsonResponse
    {
        $link = $this->links->activeFor($jobCard);

        return $this->success($link ? $this->presentLink($link) : null);
    }

    /**
     * Create the link if there is none, or replace it (the old one stops working).
     */
    public function regenerateLink(Request $request, JobCard $jobCard): JsonResponse
    {
        $link = $this->links->activeFor($jobCard)
            ? $this->links->regenerate($jobCard, $request->user())
            : $this->links->ensure($jobCard, $request->user());

        return $this->success($this->presentLink($link), 'Customer link ready');
    }

    public function revokeLink(JobCard $jobCard): JsonResponse
    {
        $this->links->revoke($jobCard);

        return $this->success(null, 'Customer link disabled');
    }

    public function messages(JobCard $jobCard): JsonResponse
    {
        $messages = $jobCard->customerMessages()->with('sentBy:id,name')->limit(50)->get();

        return $this->success($messages->map(fn (CustomerMessage $m) => $this->messages->present($m))->values());
    }

    /**
     * The advisor sent it from the workshop phone.
     */
    public function markSent(Request $request, JobCard $jobCard, CustomerMessage $customerMessage): JsonResponse
    {
        $this->messages->markSent($customerMessage, $request->user());

        return $this->success($this->messages->present($customerMessage->fresh('sentBy')), 'Marked as sent');
    }

    public function dismiss(JobCard $jobCard, CustomerMessage $customerMessage): JsonResponse
    {
        if ($customerMessage->template === 'decision_recorded') {
            // The customer must always be told about decisions recorded for them
            throw new EstimateException('This message tells the customer about decisions recorded on their behalf and must be sent.');
        }

        $customerMessage->update(['status' => CustomerMessage::DISMISSED]);

        return $this->success($this->messages->present($customerMessage), 'Message dismissed');
    }

    /**
     * Remind the customer about items still waiting for approval.
     */
    public function remind(Request $request, JobCard $jobCard, EstimateDecisionService $decisions): JsonResponse
    {
        $estimate = $decisions->currentEstimate($jobCard);
        $pending = $estimate->lines->where('approval_status', JobCardEstimateLine::PENDING)->count();

        if (!$pending) {
            throw new EstimateException('Nothing on the estimate is waiting for approval.');
        }

        $message = $this->messages->prepare($jobCard, 'approval_reminder', ['count' => $pending], $request->user());

        return $this->created($this->messages->present($message), 'Reminder ready to send');
    }

    /**
     * Make photos/videos visible on the customer page and prepare a message about them.
     */
    public function shareMedia(Request $request, JobCard $jobCard): JsonResponse
    {
        $data = $request->validate([
            'media_ids' => 'required|array|min:1',
            'media_ids.*' => 'integer',
        ]);

        $updated = JobCardMedia::where('job_card_id', $jobCard->id)
            ->whereIn('id', $data['media_ids'])
            ->update(['customer_visible' => true]);

        if ($updated !== count(array_unique($data['media_ids']))) {
            throw new EstimateException('Some photos or videos do not belong to this job card.');
        }

        $message = $this->messages->prepare($jobCard, 'media_shared', [], $request->user());

        return $this->created($this->messages->present($message), 'Shared; message ready to send');
    }

    private function presentLink($link): array
    {
        return [
            'url' => $link->url(),
            'expires_at' => $link->expires_at?->toIso8601String(),
            'last_viewed_at' => $link->last_viewed_at?->toIso8601String(),
            'created_at' => $link->created_at?->toIso8601String(),
        ];
    }
}
