<?php

namespace App\Modules\Workshop\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VehicleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'full_name' => $this->full_name,
            'registration_no' => $this->registration_no,
            'vin' => $this->vin,
            'year' => $this->year,
            'color' => $this->color,
            'fuel_type' => $this->fuel_type,
            'transmission' => $this->transmission,
            'engine_no' => $this->engine_no,
            'chassis_no' => $this->chassis_no,
            'insurance_company' => $this->insurance_company,
            'insurance_policy_no' => $this->insurance_policy_no,
            'insurance_expiry' => $this->insurance_expiry?->toDateString(),
            'notes' => $this->notes,
            'last_service_at' => $this->last_service_at?->toDateString(),
            'last_service_km' => $this->last_service_km,
            'next_service_due_date' => $this->next_service_due_date?->toDateString(),
            'next_service_due_km' => $this->next_service_due_km,
            'is_active' => $this->is_active,
            'make' => $this->whenLoaded('make', fn() => [
                'id' => $this->make->id,
                'name' => $this->make->name,
            ]),
            'model' => $this->whenLoaded('model', fn() => [
                'id' => $this->model->id,
                'name' => $this->model->name,
            ]),
            'category' => $this->whenLoaded('category', fn() => [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'slug' => $this->category->slug,
            ]),
            'customer' => $this->whenLoaded('customer', fn() => [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'phone' => $this->customer->phone,
            ]),
            'job_cards_count' => $this->whenCounted('jobCards'),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
