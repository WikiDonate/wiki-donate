<?php

namespace App\Http\Resources\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RevisionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'oldContent' => $this->old_content,
            'newContent' => $this->new_content,
            'versionNumber' => $this->version,
            'createdAt' => $this->created_at->format('d F, Y H:i'),
            'user' => $this->user,
        ];
    }
}
