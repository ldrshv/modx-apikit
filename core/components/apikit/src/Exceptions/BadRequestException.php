<?php

declare(strict_types=1);

namespace ApiKit\Exceptions;

use Throwable;

final class BadRequestException extends HttpException
{
    public function __construct(string $message = 'Некорректный запрос', ?Throwable $previous = null)
    {
        parent::__construct(400, $message, 'BAD_REQUEST', $previous);
    }
}
