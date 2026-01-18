<?php

namespace App\Services\Contracts;

interface TranscriptServiceInterface
{
    /**
     * Extract actionable to-do items from a transcript payload.
     */
    public function extractTodos(array $payload): array;
}
