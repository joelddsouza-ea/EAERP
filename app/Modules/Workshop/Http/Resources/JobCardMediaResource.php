<?php

namespace App\Modules\Workshop\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JobCardMediaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'media_type' => $this->media_type,
            // e.g. fitted_part, or the side of the car on drop photos (front/rear/left/right)
            'tag' => $this->tag,
            // Short-lived signed URLs: <img>/<video> can't send the API bearer token
            'url' => $this->url(),
            'thumbnail_url' => $this->url(true),
            'mime' => $this->mime,
            'size_bytes' => $this->size_bytes,
            'duration_seconds' => $this->duration_seconds,
            'description' => $this->description,
            'customer_visible' => $this->customer_visible,
            'uploaded_by' => $this->uploaded_by,
            'captured_at' => $this->captured_at?->toIso8601String(),
        ];
    }
}
