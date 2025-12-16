<?php

namespace App\Http\Controllers;

use App\Http\Requests\User\UserRegisterRequest;
use App\Http\Requests\User\UserUpdateRequest;
use App\Services\Contracts\UserServiceInterface;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Throwable;

class UserController extends Controller
{
    use ResponseTrait;

    public function __construct(
        protected UserServiceInterface $userService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = Arr::get($request->all(), 'filters', []);
        $order = Arr::get($request->all(), 'order', ['id', 'desc']);
        $limit = (int) Arr::get($request->all(), 'limit', 10);
        $page = (int) Arr::get($request->all(), 'page', 1);

        try {
            $data = $this->userService->getList($filters, $order, $limit, $page);

            return $this->successPagination('Users retrieved successfully!', $data);
        } catch (Throwable $e) {
            return $this->error('Failed to load users.', 500);
        }
    }

    public function store(UserRegisterRequest $request): JsonResponse
    {
        // try {
            $user = $this->userService->register($request->validated());

            return $this->success('User registered successfully!', $user);
        // } catch (ValidationException $e) {
        //     return $this->validationError($e);
        // } catch (Throwable $e) {
        //     return $this->error('Failed to register user.', 500);
        // }
    }

    public function show(int $id): JsonResponse
    {
        try {
            $user = $this->userService->detail($id);

            return $this->success('User retrieved successfully!', $user);
        } catch (Throwable $e) {
            return $this->error('Failed to load user.', 500);
        }
    }

    public function update(UserUpdateRequest $request, int $id): JsonResponse
    {
        try {
            $updated = $this->userService->update($id, $request->validated());

            return $updated
                ? $this->success('User updated successfully!')
                : $this->error('Nothing to update.', 422);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->error('Failed to update user.', 500);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            $this->userService->delete($id);

            return $this->success('User deleted successfully!');
        } catch (Throwable $e) {
            return $this->error('Failed to delete user.', 500);
        }
    }
}

