<?php

namespace App\Modules\Crm\Http\Controllers;

use App\Core\Http\Controllers\Api\ApiController;
use App\Modules\Crm\Services\CrmMessageService;
use App\Modules\Workshop\Models\CustomerMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Messages to send": CRM messages waiting for a one-tap WhatsApp send (share mode).
 */
class MessageQueueController extends ApiController
{
    public function __construct(protected CrmMessageService $messages) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $everyone = $request->boolean('everyone') && $user->hasPermission('crm.leads.manage');

        $q = CustomerMessage::query()
            ->where('purpose', 'crm')
            ->where('status', CustomerMessage::PREPARED)
            ->where('scheduled_at', '<=', now())
            ->when(!$everyone, fn ($q) => $q->where(fn ($w) => $w->where('assigned_to', $user->id)->orWhereNull('assigned_to')));

        $rows = (clone $q)->orderBy('scheduled_at')->limit(100)->get();

        return $this->success([
            'rows' => $rows->map(fn ($m) => $this->messages->present($m))->values(),
            'total' => (clone $q)->count(),
            'sent_today' => CustomerMessage::where('purpose', 'crm')->where('sent_by', $user->id)->whereDate('sent_at', today())->count(),
        ]);
    }

    public function sent(Request $request, CustomerMessage $message): JsonResponse
    {
        abort_unless($message->purpose === 'crm', 404);
        $this->messages->markSent($message, $request->user());

        return $this->success($this->messages->present($message->fresh()), 'Marked as sent');
    }

    public function dismiss(CustomerMessage $message): JsonResponse
    {
        abort_unless($message->purpose === 'crm', 404);
        $message->update(['status' => CustomerMessage::DISMISSED]);

        return $this->success(null, 'Skipped');
    }

    /**
     * A lead's / customer's CRM messages (for their timeline).
     */
    public function forLead(int $leadId): JsonResponse
    {
        return $this->success(CustomerMessage::where('lead_id', $leadId)->latest('id')->limit(50)->get()->map(fn ($m) => $this->messages->present($m))->values());
    }
}
