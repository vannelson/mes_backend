<?php

namespace App\Services;

use App\Http\Resources\User\UserResource;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\Contracts\UserServiceInterface;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UserService implements UserServiceInterface
{
    public function __construct(
        protected UserRepositoryInterface $userRepository
    ) {
    }

    public function getList(array $filters = [], array $order = [], int $limit = 10, int $page = 1): array
    {
        return UserResource::collection(
            $this->userRepository->listing($filters, $order, $limit, $page)
        )->response()->getData(true);
    }

    public function detail(int $id): array
    {
        return (new UserResource(
            $this->userRepository->findById($id)
        ))->response()->getData(true);
    }

    public function register(array $data): array
    {
        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }

        // Handle image upload
        if (isset($data['picture_data'])) {
            $data['picture_url'] = $this->handleImageUpload($data['picture_data']);
            unset($data['picture_data']);
        }

        $user = $this->userRepository->create($data);

        return (new UserResource($user))->response()->getData(true);
    }

    public function update(int $id, array $data): bool
    {
        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }

        // Handle image upload
        if (isset($data['picture_data'])) {
            // Delete old image if exists
            $user = $this->userRepository->findById($id);
            if ($user->picture_url) {
                $this->deleteImage($user->picture_url);
            }

            $data['picture_url'] = $this->handleImageUpload($data['picture_data']);
            unset($data['picture_data']);
        }

        return (bool) $this->userRepository->update($id, $data);
    }

    public function delete(int $id): bool
    {
        // Delete user image if exists
        $user = $this->userRepository->findById($id);
        if ($user->picture_url) {
            $this->deleteImage($user->picture_url);
        }

        return $this->userRepository->delete($id);
    }

    /**
     * Handle image upload from base64 data
     */
    protected function handleImageUpload(string $base64Data): string
    {
        // Remove data:image/...;base64, prefix if present
        if (preg_match('/^data:image\/(\w+);base64,/', $base64Data, $matches)) {
            $extension = $matches[1];
            $base64Data = substr($base64Data, strpos($base64Data, ',') + 1);
        } else {
            $extension = 'png'; // default
        }

        // Decode base64
        $imageData = base64_decode($base64Data);

        // Generate unique filename
        $filename = 'user_' . time() . '_' . Str::random(10) . '.' . $extension;

        // Ensure directory exists
        $directory = public_path('images/users');
        if (!file_exists($directory)) {
            mkdir($directory, 0755, true);
        }

        // Save file
        $path = $directory . '/' . $filename;
        file_put_contents($path, $imageData);

        // Return relative URL path
        return '/images/users/' . $filename;
    }

    /**
     * Delete image file
     */
    protected function deleteImage(string $pictureUrl): void
    {
        $path = public_path($pictureUrl);
        if (file_exists($path)) {
            unlink($path);
        }
    }
}

