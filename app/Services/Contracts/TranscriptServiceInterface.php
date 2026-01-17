<?php

namespace App\Services\Contracts;

use Illuminate\Http\UploadedFile;

interface TranscriptServiceInterface
{
    /**
     * Parse an uploaded transcript JSON file.
     */
    public function parseUpload(UploadedFile $file): array;
}
