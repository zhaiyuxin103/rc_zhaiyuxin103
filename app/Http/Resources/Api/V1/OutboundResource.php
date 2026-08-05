<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Outbound;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Outbound
 */
class OutboundResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'http_method' => $this->http_method,
            'target_url' => $this->target_url,
            'attempt_count' => $this->attempt_count,
            'max_attempts' => $this->max_attempts,
            'next_attempt_at' => $this->next_attempt_at?->toISOString(),
            'last_attempt_at' => $this->last_attempt_at?->toISOString(),
            'succeeded_at' => $this->succeeded_at?->toISOString(),
            'failed_at' => $this->failed_at?->toISOString(),
            'last_response_status' => $this->last_response_status,
            'last_error' => $this->last_error,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
