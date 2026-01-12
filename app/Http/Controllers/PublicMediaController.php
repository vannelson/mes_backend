<?php

namespace App\Http\Controllers;

use App\Models\ScreenMedia;
use App\Models\VirtualScreen;
use Illuminate\Support\Facades\Storage;

class PublicMediaController extends Controller
{
    public function show(string $shareToken, int $mediaId)
    {
        $screen = VirtualScreen::query()
            ->where('share_token', $shareToken)
            ->where('is_active', true)
            ->first();

        if (!$screen) {
            return response()->json([
                'status' => false,
                'message' => 'Virtual screen not found or inactive.',
            ], 404);
        }

        $media = ScreenMedia::query()
            ->where('id', $mediaId)
            ->where('virtual_screen_id', $screen->id)
            ->first();

        if (!$media) {
            return response()->json([
                'status' => false,
                'message' => 'Media not found.',
            ], 404);
        }

        $disk = Storage::disk('public');
        if (!$disk->exists($media->path)) {
            return response()->json([
                'status' => false,
                'message' => 'Media file not found.',
            ], 404);
        }

        return response()->file($disk->path($media->path), [
            'Content-Type' => $media->mime_type,
            'Content-Disposition' => 'inline; filename="' . $media->original_name . '"',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
