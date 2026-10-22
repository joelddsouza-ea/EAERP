<?php

namespace App\Modules\Workshop\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'display_name' => $this->display_name,
            'phone' => $this->phone,
            'alt_phone' => $this->alt_phone,
            'email' => $this->email,
            'address' => $this->address,
            'city' => $this->city,
            'state' => $this->state,
            'pincode' => $this->pincode,
            'gstin' => $this->gstin,
            'customer_type' => $this->customer_type,
            'is_fleet' => (bool) $this->is_fleet,
            'fleet_driver_limit' => $this->fleet_driver_limit !== null ? (float) $this->fleet_driver_limit : null,
            'source' => $this->source,
            'company_name' => $this->company_name,
            'notes' => $this->notes,
            'is_active' => $this->is_active,
            'vehicles_count' => $this->whenCounted('vehicles'),
            'job_cards_count' => $this->whenCounted('jobCards'),
            'vehicles' => VehicleResource::collection($this->whenLoaded('vehicles')),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
