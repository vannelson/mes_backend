<?php

namespace App\Http\Controllers;

use App\Http\Requests\Machine\MachineStoreRequest;
use App\Http\Requests\Machine\MachineUpdateRequest;
use App\Models\Machine;
use App\Services\Contracts\MachineServiceInterface;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
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

        // Accept top-level query params (e.g., ?q=FSK) as filters for compatibility with new UI
        foreach (['q', 'production_area', 'machine_type', 'machine_no', 'cost_center_new'] as $key) {
            $value = $request->get($key);
            if ($value !== null && $value !== '') {
                $filters[$key] = $value;
            }
        }

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
            $data = $request->validated();

            if ($request->hasFile('image')) {
                $image = $request->file('image');
                $extension = $image->getClientOriginalExtension() ?: $image->guessExtension() ?: 'jpg';
                $filename = sprintf('machine_%s_%s.%s', $id, Str::uuid(), $extension);
                $targetDir = public_path('images/machines');

                if (! File::isDirectory($targetDir)) {
                    File::makeDirectory($targetDir, 0755, true);
                }

                $image->move($targetDir, $filename);
                unset($data['image']);

                $metadata = $data['metadata'] ?? null;
                if (! is_array($metadata)) {
                    $existing = Machine::find($id);
                    $metadata = is_array($existing?->metadata) ? $existing->metadata : [];
                }
                $publicPath = "/images/machines/{$filename}";
                $metadata['image_filename'] = $filename;
                $metadata['image_url'] = $publicPath;
                $metadata['urlpath'] = $publicPath;
                $data['metadata'] = $metadata;
            }

            $updated = $this->machineService->update($id, $data);

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
