<?php

namespace App\Modules\Workshop\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JobCardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'job_number' => $this->job_number,
            'status' => $this->status,
            'status_changed_at' => $this->status_changed_at?->toIso8601String(),
            'cancel_reason' => $this->cancel_reason,
            'service_type' => $this->service_type,
            'service_mode' => $this->service_mode ?? 'drive_in',
            'priority' => $this->priority,
            'customer_id' => $this->customer_id,
            'vehicle_id' => $this->vehicle_id,
            'package_id' => $this->package_id,
            'oil_type_id' => $this->oil_type_id,
            // The job sheet screen compares these with the logged-in user (view only for others)
            'technician_id' => $this->technician_id,
            // Lead + co-technicians: all may fill the job sheets
            'technician_ids' => collect($this->relationLoaded('technicians') ? $this->technicians->pluck('id') : [])
                ->push($this->technician_id)->filter()->unique()->values(),
            'service_advisor_id' => $this->service_advisor_id,

            // Customer & Vehicle
            'customer' => $this->whenLoaded('customer', fn() => [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'phone' => $this->customer->phone,
                'is_fleet' => (bool) $this->customer->is_fleet,
            ]),
            // A fleet cab: the driver who brought it in
            'fleet_driver_id' => $this->fleet_driver_id,
            'fleet_driver' => $this->fleet_driver_id && $this->relationLoaded('fleetDriver') && $this->fleetDriver
                ? ['id' => $this->fleetDriver->id, 'name' => $this->fleetDriver->name, 'phone' => $this->fleetDriver->phone]
                : null,
            'vehicle' => $this->whenLoaded('vehicle', fn() => [
                'id' => $this->vehicle->id,
                'registration_no' => $this->vehicle->registration_no,
                'full_name' => $this->vehicle->full_name,
                // Objects, matching the vehicle and job card list endpoints
                'make' => $this->vehicle->make?->only(['id', 'name']),
                'model' => $this->vehicle->model?->only(['id', 'name']),
                'category' => $this->vehicle->category?->only(['id', 'name']),
            ]),

            // Package & Service
            'package' => $this->whenLoaded('package', fn() => [
                'id' => $this->package->id,
                'name' => $this->package->name,
                'activity_points' => $this->package->activity_points,
            ]),
            'oil_type' => $this->whenLoaded('oilType', fn() => [
                'id' => $this->oilType->id,
                'name' => $this->oilType->name,
            ]),

            // Assignments
            'bay' => $this->whenLoaded('bay', fn() => [
                'id' => $this->bay->id,
                'name' => $this->bay->name,
                'code' => $this->bay->code,
            ]),
            'branch' => $this->whenLoaded('branch', fn() => [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
                'code' => $this->branch->code,
            ]),
            'service_advisor' => $this->whenLoaded('serviceAdvisor', fn() => [
                'id' => $this->serviceAdvisor->id,
                'name' => $this->serviceAdvisor->name,
            ]),
            'technician' => $this->whenLoaded('technician', fn() => [
                'id' => $this->technician->id,
                'name' => $this->technician->name,
            ]),

            // Vehicle state
            'km_reading' => $this->km_reading,
            'fuel_level' => $this->fuel_level,
            'inventory_items' => $this->inventory_items,

            // Issues & Observations
            'customer_complaints' => $this->customer_complaints,
            'advisor_observations' => $this->advisor_observations,
            'internal_notes' => $this->internal_notes,

            // Timestamps
            'estimated_delivery' => $this->estimated_delivery?->toIso8601String(),
            'time_in' => $this->time_in?->toIso8601String(),
            'work_started_at' => $this->work_started_at?->toIso8601String(),
            'work_completed_at' => $this->work_completed_at?->toIso8601String(),
            'qc_completed_at' => $this->qc_completed_at?->toIso8601String(),
            'time_out' => $this->time_out?->toIso8601String(),

            // Pricing
            'package_price' => $this->package_price,
            'addons_total' => $this->addons_total,
            'labor_total' => $this->labor_total,
            'parts_total' => $this->parts_total,
            'third_party_total' => $this->third_party_total,
            'discount' => $this->discount,
            'discount_reason' => $this->discount_reason,
            'total_amount' => $this->total_amount,
            'estimated_amount' => $this->estimated_amount,

            // Approval
            'customer_approved' => $this->customer_approved,
            'approved_at' => $this->approved_at?->toIso8601String(),

            // Items & Addons
            'items' => $this->whenLoaded('items'),
            'addons' => $this->whenLoaded('addons'),

            // Status history
            'status_history' => $this->whenLoaded('statusHistory', fn() =>
                $this->statusHistory->map(fn($h) => [
                    'from_status' => $h->from_status,
                    'to_status' => $h->to_status,
                    'notes' => $h->notes,
                    'override' => (bool) ($h->meta['override'] ?? false),
                    'changed_by' => $h->changedBy?->name,
                    'changed_at' => $h->created_at->toIso8601String(),
                ])
            ),

            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
