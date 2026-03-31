<?php

namespace App\Http\Resources\SupplierChangeControl;

use App\Models\SupplierChangeControl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class SupplierChangeControlResource extends JsonResource
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

    protected function resolveAttachmentUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return url(Storage::url($path));
    }

    public function toArray(Request $request): array
    {
        $step = (int) ($this->current_step ?? 1);

        return [
            'id' => $this->id,
            'supplier_name' => $this->supplier_name,
            'address' => $this->address,
            'tel_fax' => $this->tel_fax,
            'status' => $this->status,
            'current_step' => $step,
            'current_step_label' => SupplierChangeControl::STEP_LABELS[$step] ?? null,
            'step_labels' => SupplierChangeControl::STEP_LABELS,
            'notes' => $this->notes,
            'attachment_path' => $this->attachment_path,
            'attachment_name' => $this->attachment_path ? basename($this->attachment_path) : null,
            'attachment_url' => $this->resolveAttachmentUrl($this->attachment_path),
            'initiated_at' => $this->initiated_at,
            'assessed_at' => $this->assessed_at,
            'analyzed_at' => $this->analyzed_at,
            'implemented_at' => $this->implemented_at,
            'closed_at' => $this->closed_at,
            'created_by_user_id' => $this->created_by_user_id,
            'created_by_name' => $this->resolveUserName($this->creator),
            'updated_by_user_id' => $this->updated_by_user_id,
            'updated_by_name' => $this->resolveUserName($this->updater),
            'events_count' => $this->whenCounted('events'),
            'events' => SupplierChangeControlEventResource::collection(
                $this->whenLoaded('events')
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
