<?php

namespace App\Services\Contracts;

interface AuthServiceInterface
{
    public function login(array $data): array;
}

