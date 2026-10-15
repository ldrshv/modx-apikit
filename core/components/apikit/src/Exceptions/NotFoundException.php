<?php

declare(strict_types=1);

namespace ApiKit\Exceptions;

final class NotFoundException extends HttpException
{
    public function __construct(string $message = 'Маршрут не найден')
    {
        parent::__construct(404, $message, 'NOT_FOUND');
    }
}
