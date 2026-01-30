<?php

namespace App\Http\Resources\User;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $pictureUrl = $this->picture_url;
        if ($pictureUrl) {
            $clean = ltrim($pictureUrl, '/');
            if (str_starts_with($clean, 'images/')) {
                $clean = substr($clean, strlen('images/'));
            }
            $pictureUrl = url("/api/v1/images/{$clean}");
        }

        return [
            'id' => $this->id,
            'firstname' => $this->firstname,
            'lastname' => $this->lastname,
            'middlename' => $this->middlename,
            'position' => $this->position,
            'address' => $this->address,
            'user_type' => $this->user_type,
            'finger_print' => $this->finger_print,
            'email' => $this->email,
            'picture_url' => $pictureUrl,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
