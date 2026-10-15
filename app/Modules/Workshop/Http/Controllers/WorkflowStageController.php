<?php

namespace App\Modules\Workshop\Http\Controllers;

use App\Core\Http\Controllers\Api\ApiController;
use App\Modules\Workshop\Models\WorkflowStage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Job stages master (Job Sheet Masters › Job stages): automatic or manual entry,
 * what each stage waits for, and the WhatsApp message to the customer.
 */
class WorkflowStageController extends ApiController
{
    public function index(): JsonResponse
    {
        return $this->success([
            'stages' => WorkflowStage::orderBy('sort_order')->get()->map(fn (WorkflowStage $s) => $this->present($s))->values(),
            'placeholders' => WorkflowStage::PLACEHOLDERS,
        ]);
    }

    public function update(Request $request, WorkflowStage $workflowStage): JsonResponse
    {
        $data = $request->validate([
            'waiting_for' => 'sometimes|nullable|string|max:255',
            'auto' => 'sometimes|boolean',
            'notify_customer' => ['sometimes', Rule::in(WorkflowStage::NOTIFY_MODES)],
            'message' => 'sometimes|nullable|string|max:1000',
            'gallery_update' => 'sometimes|boolean',
        ]);

        if (($data['auto'] ?? false) && !in_array($workflowStage->status, WorkflowStage::AUTO_CAPABLE, true)) {
            return $this->error(WorkflowStage::ENTERED_BY[$workflowStage->status] ?? 'This stage is always entered by a person.', 422);
        }

        $message = array_key_exists('message', $data) ? $data['message'] : $workflowStage->message;

        if (($data['notify_customer'] ?? $workflowStage->notify_customer) !== WorkflowStage::NOTIFY_OFF && !trim((string) $message)) {
            return $this->error('Write the message the customer gets, or switch the message off.', 422);
        }

        $workflowStage->update([...$data, 'customized_at' => now()]);

        return $this->success($this->present($workflowStage), 'Stage saved');
    }

    private function present(WorkflowStage $stage): array
    {
        return [
            'id' => $stage->id,
            'status' => $stage->status,
            'label' => $stage->label,
            'waiting_for' => $stage->waiting_for,
            'auto' => $stage->auto && in_array($stage->status, WorkflowStage::AUTO_CAPABLE, true),
            'auto_capable' => in_array($stage->status, WorkflowStage::AUTO_CAPABLE, true),
            'entered_by' => WorkflowStage::ENTERED_BY[$stage->status] ?? null,
            'notify_customer' => $stage->notify_customer,
            'message' => $stage->message,
            'gallery_update' => $stage->gallery_update,
            'customized_at' => $stage->customized_at?->toIso8601String(),
        ];
    }
}
