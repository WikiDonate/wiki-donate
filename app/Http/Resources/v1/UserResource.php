<?php

namespace App\Http\Resources\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'uuid' => $this->uuid,
            'username' => $this->username,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'roles' => $this->roles->pluck('name'),
            'createdAt' => $this->created_at->format('d F, Y'),
        ];

        if ($token = $this->whenLoaded('tokens', function () {
            return $this->createToken('authToken')->plainTextToken;
        })) {
            $data['token'] = $token;
        }

        return $data;
    }
}
