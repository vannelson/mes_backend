<?php

namespace App\Services;

use App\Http\Resources\SupplierChangeControl\SupplierChangeControlResource;
use App\Models\SupplierChangeControl;
use App\Repositories\Contracts\SupplierChangeControlRepositoryInterface;
use App\Services\Contracts\SupplierChangeControlServiceInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SupplierChangeControlService implements SupplierChangeControlServiceInterface
{
    public function __construct(
        protected SupplierChangeControlRepositoryInterface $supplierChangeControlRepository
    ) {
    }

    public function getList(array $filters = [], array $order = [], int $limit = 10, int $page = 1): array
    {
        return SupplierChangeControlResource::collection(
            $this->supplierChangeControlRepository->listing($filters, $order, $limit, $page)
        )->response()->getData(true);
    }

    public function detail(int $id): array
    {
        return (new SupplierChangeControlResource(
            $this->supplierChangeControlRepository->findById($id)
                ->load(['creator', 'updater', 'events.user'])
        ))->response()->getData(true);
    }

    public function create(
        array $data,
        ?UploadedFile $attachment = null,
        ?string $eventNote = null,
        ?int $userId = null
    ): array {
        $payload = $this->sanitizePayload($data);
        $step = $this->normalizeStep((int) ($payload['current_step'] ?? 1));
        $payload['current_step'] = $step;
        $payload['status'] = $payload['status'] ?? $this->statusByStep($step);

        if (! isset($payload['created_by_user_id']) && $userId) {
            $payload['created_by_user_id'] = $userId;
        }
        if (! isset($payload['updated_by_user_id']) && $userId) {
            $payload['updated_by_user_id'] = $userId;
        }

        if ($attachment) {
            $payload['attachment_path'] = $this->storeAttachment($attachment);
        }

        $now = now();
        $this->applyStepTimestamps($payload, $step, $now);
        $record = $this->supplierChangeControlRepository->create($payload);

        $this->logEvent($record, 'created', $step, $eventNote, $userId, [
            'status' => $record->status,
        ]);

        return (new SupplierChangeControlResource(
            $record->load(['creator', 'updater', 'events.user'])
        ))->response()->getData(true);
    }

    public function update(
        int $id,
        array $data,
        ?UploadedFile $attachment = null,
        ?string $eventNote = null,
        ?int $userId = null
    ): array {
        /** @var SupplierChangeControl $existing */
        $existing = $this->supplierChangeControlRepository->findById($id);
        $payload = $this->sanitizePayload($data);

        if ($userId) {
            $payload['updated_by_user_id'] = $userId;
        }

        if (array_key_exists('current_step', $payload)) {
            $payload['current_step'] = $this->normalizeStep((int) $payload['current_step']);
            if (! array_key_exists('status', $payload)) {
                $payload['status'] = $this->statusByStep($payload['current_step']);
            }
            $this->applyStepTimestamps($payload, (int) $payload['current_step'], now(), $existing);
        }

        $oldAttachmentPath = $existing->attachment_path;
        if ($attachment) {
            $payload['attachment_path'] = $this->storeAttachment($attachment);
        }

        $updated = (bool) $this->supplierChangeControlRepository->update($id, $payload);
        if (! $updated) {
            if (isset($payload['attachment_path'])) {
                Storage::disk('public')->delete($payload['attachment_path']);
            }
            return [];
        }

        if ($attachment && $oldAttachmentPath) {
            Storage::disk('public')->delete($oldAttachmentPath);
        }

        /** @var SupplierChangeControl $record */
        $record = $this->supplierChangeControlRepository->findById($id);

        $this->logEvent(
            $record,
            'updated',
            (int) ($record->current_step ?? 1),
            $eventNote,
            $userId,
            [
                'status' => $record->status,
            ]
        );

        return (new SupplierChangeControlResource(
            $record->load(['creator', 'updater', 'events.user'])
        ))->response()->getData(true);
    }

    public function updateStep(
        int $id,
        int $step,
        ?string $status = null,
        ?string $note = null,
        ?int $userId = null
    ): array {
        $step = $this->normalizeStep($step);
        /** @var SupplierChangeControl $record */
        $record = $this->supplierChangeControlRepository->findById($id);

        $payload = [
            'current_step' => $step,
            'status' => $status ?: $this->statusByStep($step),
        ];

        if ($userId) {
            $payload['updated_by_user_id'] = $userId;
        }

        $this->applyStepTimestamps($payload, $step, now(), $record);

        $updated = (bool) $this->supplierChangeControlRepository->update($id, $payload);
        if (! $updated) {
            return [];
        }

        /** @var SupplierChangeControl $refreshed */
        $refreshed = $this->supplierChangeControlRepository->findById($id);

        $this->logEvent(
            $refreshed,
            'step_updated',
            $step,
            $note,
            $userId,
            [
                'from_step' => (int) ($record->current_step ?? 1),
                'to_step' => $step,
                'status' => $payload['status'],
            ]
        );

        return (new SupplierChangeControlResource(
            $refreshed->load(['creator', 'updater', 'events.user'])
        ))->response()->getData(true);
    }

    public function delete(int $id): bool
    {
        /** @var SupplierChangeControl $record */
        $record = $this->supplierChangeControlRepository->findById($id);
        $attachmentPath = $record->attachment_path;

        $deleted = $this->supplierChangeControlRepository->delete($id);
        if ($deleted && $attachmentPath) {
            Storage::disk('public')->delete($attachmentPath);
        }

        return $deleted;
    }

    protected function sanitizePayload(array $data): array
    {
        $allowed = [
            'supplier_name',
            'address',
            'tel_fax',
            'status',
            'current_step',
            'notes',
            'created_by_user_id',
            'updated_by_user_id',
        ];

        $payload = array_intersect_key($data, array_flip($allowed));

        foreach (['supplier_name', 'tel_fax', 'status'] as $key) {
            if (array_key_exists($key, $payload) && is_string($payload[$key])) {
                $payload[$key] = trim($payload[$key]);
            }
        }

        return $payload;
    }

    protected function storeAttachment(UploadedFile $attachment): string
    {
        $extension = strtolower($attachment->getClientOriginalExtension() ?: 'bin');
        $filename = Str::uuid()->toString() . '.' . $extension;

        return $attachment->storeAs('supplier-change-controls', $filename, 'public');
    }

    protected function normalizeStep(int $step): int
    {
        return max(1, min(5, $step));
    }

    protected function statusByStep(int $step): string
    {
        return match ($step) {
            1 => 'draft',
            2 => 'assessment',
            3 => 'analysis',
            4 => 'implementation',
            5 => 'closed',
            default => 'draft',
        };
    }

    protected function applyStepTimestamps(
        array &$payload,
        int $step,
        Carbon $now,
        ?SupplierChangeControl $existing = null
    ): void {
        $mapping = [
            1 => 'initiated_at',
            2 => 'assessed_at',
            3 => 'analyzed_at',
            4 => 'implemented_at',
            5 => 'closed_at',
        ];

        foreach ($mapping as $level => $column) {
            if ($step < $level) {
                continue;
            }

            $alreadySet = $existing?->{$column};
            if (! $alreadySet && ! array_key_exists($column, $payload)) {
                $payload[$column] = $now;
            }
        }
    }

    protected function logEvent(
        SupplierChangeControl $record,
        string $action,
        int $step,
        ?string $note = null,
        ?int $userId = null,
        ?array $payload = null
    ): void {
        $record->events()->create([
            'action' => $action,
            'step' => $step,
            'note' => $note,
            'payload' => $payload,
            'user_id' => $userId,
        ]);
    }
}

