<?php

namespace App\Http\Resources\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
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
            'editTalkPage' => $this->edit_talk_page,
            'editUserPage' => $this->edit_user_page,
            'pageReview' => $this->page_review,
            'emailFromOther' => $this->email_from_other,
            'successfulMention' => $this->successful_mention,
        ];
    }
}
