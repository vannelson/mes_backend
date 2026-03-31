<?php

namespace App\Http\Resources\SupplierChangeControl;

use App\Models\SupplierChangeControl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierChangeControlEventResource extends JsonResource
{
    protected function resolveUserName($user): ?string
    {
        if (! $user) {
            return null;
        }

        $parts = array_filter([
            $user->firstname ?? null,
            $user->middlename ?? null,
            $user->lastname ?? null,
        ]);

        return !empty($parts) ? implode(' ', $parts) : ($user->email ?? null);
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'step' => $this->step,
            'step_label' => $this->step
                ? (SupplierChangeControl::STEP_LABELS[$this->step] ?? null)
                : null,
            'note' => $this->note,
            'payload' => $this->payload,
            'user_id' => $this->user_id,
            'user_name' => $this->resolveUserName($this->user),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
