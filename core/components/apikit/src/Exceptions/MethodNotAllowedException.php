<?php

declare(strict_types=1);

namespace ApiKit\Exceptions;

final class MethodNotAllowedException extends HttpException
{
    public function __construct(string $message = 'Метод не поддерживается')
    {
        parent::__construct(405, $message, 'METHOD_NOT_ALLOWED');
    }
}
