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
        return [
            'uuid' => $this->uuid,
            'username' => $this->username,
            'email' => $this->email,
            'token' => $this->createToken('authToken')->plainTextToken,
            'roles' => $this->roles->pluck('name'),
            'createdAt' => $this->created_at->format('d F, Y'),
        ];
    }
}
