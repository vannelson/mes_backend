<?php

namespace App\Services;

use App\Http\Resources\WorkOrderComment\WorkOrderCommentResource;
use App\Repositories\Contracts\WorkOrderCommentRepositoryInterface;
use App\Services\Contracts\WorkOrderCommentServiceInterface;
use Illuminate\Support\Arr;

class WorkOrderCommentService implements WorkOrderCommentServiceInterface
{
    public function __construct(
        protected WorkOrderCommentRepositoryInterface $workOrderCommentRepository
    ) {
    }

    public function getList(array $filters = [], array $order = [], int $limit = 10, int $page = 1): array
    {
        return WorkOrderCommentResource::collection(
            $this->workOrderCommentRepository->listing($filters, $order, $limit, $page)
        )->response()->getData(true);
    }

    public function detail(int $id): array
    {
        return (new WorkOrderCommentResource($this->workOrderCommentRepository->findById($id)->load('user')))
            ->response()
            ->getData(true);
    }

    public function create(array $data): array
    {
        $payload = $data;
        $payload['attachments'] = $this->normalizeJson($payload['attachments'] ?? null);
        $payload['metadata'] = $this->normalizeJson($payload['metadata'] ?? null);

        $comment = $this->workOrderCommentRepository->create($payload)->load('user');

        if (empty($comment->thread_id)) {
            $this->workOrderCommentRepository->update($comment->id, ['thread_id' => $comment->id]);
            $comment->thread_id = $comment->id;
        }

        return (new WorkOrderCommentResource($comment))->response()->getData(true);
    }

    public function update(int $id, array $data): array
    {
        $payload = $data;
        if (array_key_exists('attachments', $payload)) {
            $payload['attachments'] = $this->normalizeJson($payload['attachments']);
        }
        if (array_key_exists('metadata', $payload)) {
            $payload['metadata'] = $this->normalizeJson($payload['metadata']);
        }

        $updated = (bool) $this->workOrderCommentRepository->update($id, $payload);

        if (!$updated) {
            return [];
        }

        return (new WorkOrderCommentResource($this->workOrderCommentRepository->findById($id)->load('user')))
            ->response()
            ->getData(true);
    }

    public function delete(int $id): bool
    {
        return $this->workOrderCommentRepository->delete($id);
    }

    protected function normalizeJson(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return Arr::wrap($value);
    }
}
