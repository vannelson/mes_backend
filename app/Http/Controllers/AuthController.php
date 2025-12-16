<?php

namespace App\Http\Controllers;

use App\Services\Contracts\AuthServiceInterface;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Handles user authentication.
 */
class AuthController extends Controller
{
    use ResponseTrait;

    public function __construct(
        protected AuthServiceInterface $authService
    ) {
    }

    public function login(Request $request): JsonResponse
    {
        // try {
            $validated = $request->validate([
                'email' => 'required|email',
                'password' => 'required',
            ]);

            $authData = $this->authService->login($validated);
            $userArray = is_array($authData['user'])
                ? $authData['user']
                : $authData['user']->toArray();

            return $this->successLogin($userArray, $authData['token']);
        // } catch (ValidationException $e) {
            return $this->error('Invalid credentials.', 401);
        // } catch (Throwable $e) {
        //     return $this->error('Login failed.', 500);
        // }
    }
}

