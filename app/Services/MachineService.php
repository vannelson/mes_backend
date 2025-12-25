<?php

namespace App\Services;

use App\Http\Resources\Machine\MachineResource;
use App\Repositories\Contracts\MachineRepositoryInterface;
use App\Services\Contracts\MachineServiceInterface;

class MachineService implements MachineServiceInterface
{
    public function __construct(
        protected MachineRepositoryInterface $machineRepository
    ) {
    }

    public function getList(array $filters = [], array $order = [], int $limit = 10, int $page = 1): array
    {
        return MachineResource::collection(
            $this->machineRepository->listing($filters, $order, $limit, $page)
        )->response()->getData(true);
    }

    public function detail(int $id): array
    {
        return (new MachineResource($this->machineRepository->findById($id)))->response()->getData(true);
    }

    public function create(array $data): array
    {
        $machine = $this->machineRepository->create($data);

        return (new MachineResource($machine))->response()->getData(true);
    }

    public function update(int $id, array $data): array
    {
        $updated = (bool) $this->machineRepository->update($id, $data);

        if (! $updated) {
            return [];
        }

        return (new MachineResource($this->machineRepository->findById($id)))->response()->getData(true);
    }

    public function delete(int $id): bool
    {
        return $this->machineRepository->delete($id);
    }
}
