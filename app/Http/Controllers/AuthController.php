<?php

namespace App\Http\Controllers;

use App\Http\Resources\User\UserResource;
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
            $user = $authData['user'];
            $userArray = $user instanceof \App\Models\User
                ? UserResource::make($user)->resolve()
                : UserResource::make((object) $user)->resolve();

            return $this->successLogin($userArray, $authData['token']);
        // } catch (ValidationException $e) {
            return $this->error('Invalid credentials.', 401);
        // } catch (Throwable $e) {
        //     return $this->error('Login failed.', 500);
        // }
    }

    public function confirmPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'password' => 'required',
        ]);

        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthorized.', 401);
        }

        if (!$this->authService->confirmPassword($user, $validated['password'])) {
            return $this->error('Invalid password.', 422, [
                'password' => ['Invalid password.'],
            ]);
        }

        return $this->success('Password confirmed.', [
            'confirmed' => true,
        ]);
    }
}
