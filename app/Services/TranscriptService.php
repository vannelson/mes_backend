<?php

namespace App\Services;

use App\Services\Contracts\TranscriptServiceInterface;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use RuntimeException;

class TranscriptService implements TranscriptServiceInterface
{
    public function parseUpload(UploadedFile $file): array
    {
        $contents = file_get_contents($file->getRealPath());

        if ($contents === false) {
            throw new RuntimeException('Unable to read uploaded file.');
        }

        $decoded = json_decode($contents, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException('Invalid JSON file.');
        }

        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Transcript payload must be a JSON object.');
        }

        return $decoded;
    }
}
