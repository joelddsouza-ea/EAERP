<?php

namespace App\Modules\Workshop\Http\Controllers;

use App\Core\Http\Controllers\Api\ApiController;
use App\Modules\Workshop\Models\ObservationRemark;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Master list of remarks for observations (Job Sheet Masters). Remarks are
 * deactivated rather than deleted, because observations refer to them.
 */
class ObservationRemarkController extends ApiController
{
    public function index(): JsonResponse
    {
        return $this->success(ObservationRemark::orderBy('sort_order')->orderBy('id')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'label' => 'required|string|max:255|unique:observation_remarks,label',
            'needs_days' => 'sometimes|boolean',
        ]);

        $code = $base = Str::slug($data['label'], '_') ?: 'remark';
        for ($i = 2; ObservationRemark::where('code', $code)->exists(); $i++) {
            $code = "{$base}_{$i}";
        }

        $remark = ObservationRemark::create([
            'code' => $code,
            'label' => $data['label'],
            'needs_days' => $data['needs_days'] ?? str_contains($data['label'], '_'),
            'sort_order' => (int) ObservationRemark::max('sort_order') + 1,
            'is_active' => true,
            'customized_at' => now(),
        ]);

        return $this->created($remark, 'Remark added');
    }

    public function update(Request $request, ObservationRemark $observationRemark): JsonResponse
    {
        $data = $request->validate([
            'label' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('observation_remarks', 'label')->ignore($observationRemark->id)],
            'needs_days' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer|min:0',
        ]);

        $observationRemark->update([...$data, 'customized_at' => now()]);

        return $this->success($observationRemark, 'Remark saved');
    }
}
