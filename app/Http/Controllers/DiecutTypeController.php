<?php

namespace App\Http\Controllers;

use App\Http\Resources\DiecutType\DiecutTypeResource;
use App\Models\DiecutType;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Throwable;

class DiecutTypeController extends Controller
{
    use ResponseTrait;

    public function index(): JsonResponse
    {
        try {
            $types = DiecutType::query()->orderBy('code')->get();

            return $this->success(
                'Diecut types retrieved successfully!',
                DiecutTypeResource::collection($types)->resolve()
            );
        } catch (Throwable $e) {
            return $this->error('Failed to load diecut types.', 500);
        }
    }
}
