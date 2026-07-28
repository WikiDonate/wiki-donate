<?php

namespace App\Http\Resources\v1;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Resources\Json\JsonResource;

class TalkResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $decoded = json_decode($this->sections, true);
        $userIds = collect($decoded)->pluck('edited_by')->filter()->unique()->toArray();
        $users = User::whereIn('id', $userIds)->pluck('username', 'id');

        $sections = collect($decoded)->map(function ($section) use ($users) {
            $username = $section['edited_by']
                ? $users->get($section['edited_by'])
                : null;

            return [
                'uuid' => $section['uuid'],
                'title' => $section['title'],
                'content' => $section['content'],
                'edited_by' => $username ? [
                    'id' => $section['edited_by'],
                    'username' => $username,
                ] : null,
                'edited_at' => $section['edited_at']
                    ? Carbon::parse($section['edited_at'])->format('d F, Y H:i')
                    : null,
            ];
        });

        return [
            'uuid' => $this->uuid,
            'title' => $this->title,
            'slug' => $this->slug,
            'sections' => $sections,
            'created_at' => $this->created_at->format('d F, Y H:i'),

            'user' => [
                'uuid' => $this->user->uuid,
                'username' => $this->user->username,
            ],
        ];
    }
}
