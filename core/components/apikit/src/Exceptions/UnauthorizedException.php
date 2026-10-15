<?php

declare(strict_types=1);

namespace ApiKit\Exceptions;

final class UnauthorizedException extends HttpException
{
    public function __construct(string $message = 'Требуется авторизация')
    {
        parent::__construct(401, $message, 'UNAUTHORIZED');
    }
}
