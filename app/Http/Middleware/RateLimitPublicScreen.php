<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class RateLimitPublicScreen
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $key = 'public-screen:' . $request->ip();

        // Allow 60 requests per minute per IP
        if (RateLimiter::tooManyAttempts($key, 60)) {
            return response()->json([
                'status' => false,
                'message' => 'Too many requests. Please try again later.',
            ], 429);
        }

        RateLimiter::hit($key, 60);

        return $next($request);
    }
}
