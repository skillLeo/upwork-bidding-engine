<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Client
 */
class ClientResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'lead_id' => $this->lead_id,
            'lead_title' => $this->whenLoaded('lead', fn () => $this->lead?->title),
            'budget_discussed' => $this->budget_discussed,
            'agreed_scope' => $this->agreed_scope,
            'stage' => $this->stage->value,
            'notes' => $this->notes,
            'messages' => MessageResource::collection($this->whenLoaded('messages')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
