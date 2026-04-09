<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class ImageController extends Controller
{
    public function show(string $path): Response|SymfonyResponse
    {
        $clean = ltrim($path, '/');
        if (Str::contains($clean, ['..', '\\', ':'])) {
            abort(400, 'Invalid image path.');
        }

        if (Str::startsWith($clean, 'images/')) {
            $clean = Str::after($clean, 'images/');
        }

        $fullPath = public_path('images/' . $clean);
        if (is_file($fullPath)) {
            return response()->file($fullPath);
        }

        $storagePath = storage_path('app/public/' . $clean);
        if (is_file($storagePath)) {
            return response()->file($storagePath);
        }

        abort(404);
    }
}
