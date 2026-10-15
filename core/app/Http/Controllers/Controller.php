<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use ApiKit\Http\JsonResponse;

abstract class Controller
{
    protected function json($data, int $status = 200): JsonResponse
    {
        return new JsonResponse($data, $status);
    }
}
