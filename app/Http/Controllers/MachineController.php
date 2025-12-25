<?php

namespace App\Http\Controllers;

use App\Http\Requests\Machine\MachineStoreRequest;
use App\Http\Requests\Machine\MachineUpdateRequest;
use App\Services\Contracts\MachineServiceInterface;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Throwable;

class MachineController extends Controller
{
    use ResponseTrait;

    public function __construct(
        protected MachineServiceInterface $machineService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = Arr::get($request->all(), 'filters', []);
        $order = Arr::get($request->all(), 'order', ['id', 'desc']);
        $limit = (int) Arr::get($request->all(), 'limit', 10);
        $page = (int) Arr::get($request->all(), 'page', 1);

        try {
            $data = $this->machineService->getList($filters, $order, $limit, $page);

            return $this->successPagination('Machines retrieved successfully!', $data);
        } catch (Throwable $e) {
            return $this->error('Failed to load machines.', 500);
        }
    }

    public function store(MachineStoreRequest $request): JsonResponse
    {
        try {
            $machine = $this->machineService->create($request->validated());

            return $this->success('Machine created successfully!', $machine);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->error('Failed to create machine.', 500);
        }
    }

    public function show(int $id): JsonResponse
    {
        try {
            $machine = $this->machineService->detail($id);

            return $this->success('Machine retrieved successfully!', $machine);
        } catch (Throwable $e) {
            return $this->error('Failed to load machine.', 500);
        }
    }

    public function update(MachineUpdateRequest $request, int $id): JsonResponse
    {
        try {
            $updated = $this->machineService->update($id, $request->validated());

            return $updated
                ? $this->success('Machine updated successfully!', $updated)
                : $this->error('Nothing to update.', 422);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->error('Failed to update machine.', 500);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            $this->machineService->delete($id);

            return $this->success('Machine deleted successfully!');
        } catch (Throwable $e) {
            return $this->error('Failed to delete machine.', 500);
        }
    }
}
