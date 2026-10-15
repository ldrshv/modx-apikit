<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use ApiKit\Http\JsonResponse;

final class HealthController extends Controller
{
    public function index(): JsonResponse
    {
        return $this->json(['status' => 'ok']);
    }
}
