<?php

declare(strict_types=1);

namespace ApiKit\Middleware;

use ApiKit\Http\Request;
use ApiKit\Http\Response;

interface MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response;
}
