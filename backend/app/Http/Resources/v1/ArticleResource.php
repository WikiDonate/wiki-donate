<?php

namespace App\Http\Resources\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;

class ArticleResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $editable = true;
        if (Auth::check()) {
            // Only admin can edit the admin's article
            if ($this->user->hasRole(['Admin'])) {
                if (! Auth::user()->hasRole(['Admin'])) {
                    $editable = false;
                }
            }
        }

        return [
            'uuid' => $this->uuid,
            'title' => $this->title,
            'slug' => $this->slug,
            'sections' => $this->sections,
            'createdAt' => $this->created_at->format('d F, Y H:i'),
            'updatedAt' => $this->updated_at->format('d F, Y H:i'),
            'accessType' => $this->access_type ?? 'public',
            'user' => [
                'uuid' => $this->user->uuid,
                'username' => $this->user->username,
            ],
            'editable' => $editable,
        ];
    }
}
