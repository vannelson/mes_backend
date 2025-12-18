<?php

namespace App\Services;

use App\Http\Resources\TemplateRoute\TemplateRouteResource;
use App\Repositories\Contracts\TemplateRouteRepositoryInterface;
use App\Services\Contracts\TemplateRouteServiceInterface;
use Illuminate\Support\Str;

class TemplateRouteService implements TemplateRouteServiceInterface
{
    public function __construct(
        protected TemplateRouteRepositoryInterface $templateRouteRepository
    ) {
    }

    public function getList(array $filters = [], array $order = [], int $limit = 10, int $page = 1): array
    {
        return TemplateRouteResource::collection(
            $this->templateRouteRepository->listing($filters, $order, $limit, $page)
        )->response()->getData(true);
    }

    public function detail(int $id): array
    {
        return (new TemplateRouteResource($this->templateRouteRepository->findById($id)))->response()->getData(true);
    }

    public function create(array $data): array
    {
        $data['uuid'] = $data['uuid'] ?? (string) Str::uuid();
        $templateRoute = $this->templateRouteRepository->create($data);

        return (new TemplateRouteResource($templateRoute->load('manager')))->response()->getData(true);
    }

    public function update(int $id, array $data): array
    {
        $updated = (bool) $this->templateRouteRepository->update($id, $data);

        if (! $updated) {
            return [];
        }

        $templateRoute = $this->templateRouteRepository->findById($id)->load('manager');

        return (new TemplateRouteResource($templateRoute))->response()->getData(true);
    }

    public function delete(int $id): bool
    {
        return $this->templateRouteRepository->delete($id);
    }
}
