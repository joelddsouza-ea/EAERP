<?php

namespace App\Modules\Workshop\Http\Controllers;

use App\Core\Http\Controllers\Api\ApiController;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Services\CustomerDocumentService;
use App\Modules\Workshop\Services\CustomerMessageService;
use App\Modules\Workshop\Services\ServiceReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServiceReportController extends ApiController
{
    public function __construct(protected ServiceReportService $reports) {}

    public function show(JobCard $jobCard): JsonResponse
    {
        return $this->success($this->reports->build($jobCard));
    }

    public function update(Request $request, JobCard $jobCard): JsonResponse
    {
        $data = $request->validate([
            'include_activities' => 'sometimes|boolean',
            'include_additional' => 'sometimes|boolean',
            'include_observations' => 'sometimes|boolean',
            'advisor_notes' => 'sometimes|nullable|string|max:2000',
        ]);

        $this->reports->update($jobCard, $data);

        return $this->success($this->reports->build($jobCard), 'Report updated');
    }

    public function release(Request $request, JobCard $jobCard, CustomerMessageService $messages): JsonResponse
    {
        $result = $this->reports->release($jobCard, $request->user());

        return $this->success([
            'report' => $this->reports->build($jobCard),
            'message' => $messages->present($result['message']),
        ], 'Report released to the customer');
    }

    public function pdf(JobCard $jobCard, CustomerDocumentService $documents)
    {
        return $documents->reportPdf($jobCard);
    }
}
