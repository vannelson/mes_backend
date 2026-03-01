<?php

namespace App\Services\Contracts;

use App\Models\User;

interface AuthServiceInterface
{
    public function login(array $data): array;

    public function confirmPassword(User $user, string $password): bool;
}
