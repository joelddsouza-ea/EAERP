<?php

namespace App\Modules\Workshop\Http\Controllers;

use App\Core\Http\Controllers\Api\ApiController;
use App\Core\Support\WorkshopSettings;
use App\Modules\Workshop\Models\CustomerMessage;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Services\CustomerMessageService;
use App\Modules\Workshop\Services\CustomerUpdateService;
use App\Modules\Workshop\Services\JobSheetException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Send update": staff ask for the customer to be told about a finished step; the advisor
 * reads the ready message and sends it (from the bell or the job card).
 */
class CustomerUpdateController extends ApiController
{
    private const SETTINGS = ['section_message', 'stage_message'];

    public function __construct(
        protected CustomerUpdateService $updates,
        protected CustomerMessageService $messages,
    ) {}

    public function store(Request $request, JobCard $jobCard): JsonResponse
    {
        $data = $request->validate([
            'kind' => 'required|string|in:section,stage',
            'sheet_id' => 'required_if:kind,section|nullable|integer',
            'section' => 'required_if:kind,section|nullable|string|max:150',
        ]);

        try {
            $result = $this->updates->request($jobCard, $request->user(), $data['kind'], $data['sheet_id'] ?? null, $data['section'] ?? null);
        } catch (JobSheetException $e) {
            return $this->error($e->getMessage(), $e->status, $e->errors);
        }

        return $this->success(
            ['message' => $this->messages->present($result['message']), 'to_advisor' => $result['to_advisor']],
            $result['to_advisor'] ? 'Sent to the advisor to send to the customer' : 'Message ready to send',
        );
    }

    public function sent(Request $request, CustomerMessage $message): JsonResponse
    {
        return $this->act(fn () => $this->updates->markSent($message, $request->user()), 'Marked as sent');
    }

    public function skip(Request $request, CustomerMessage $message): JsonResponse
    {
        return $this->act(fn () => $this->updates->skip($message, $request->user()), 'Skipped');
    }

    public function show(CustomerMessage $message): JsonResponse
    {
        return $this->success($this->messages->present($message));
    }

    // ---------------------------------------------------------------- Job Stages & Messages › staff updates

    public function settings(): JsonResponse
    {
        return $this->success($this->settingsData());
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'section_message' => 'sometimes|required|string|max:1000',
            'stage_message' => 'sometimes|required|string|max:1000',
        ]);
        foreach ($data as $key => $value) {
            WorkshopSettings::set("updates.{$key}", trim($value));
        }

        return $this->success($this->settingsData(), 'Saved');
    }

    private function settingsData(): array
    {
        return collect(self::SETTINGS)->mapWithKeys(fn ($k) => [$k => (string) WorkshopSettings::get("updates.{$k}")])->all();
    }

    private function act(callable $step, string $message): JsonResponse
    {
        try {
            $result = $step();
        } catch (JobSheetException $e) {
            return $this->error($e->getMessage(), $e->status, $e->errors);
        }

        return $this->success($this->messages->present($result), $message);
    }
}
