<?php

declare(strict_types=1);

namespace ApiKit\Exceptions;

use ApiKit\Http\JsonResponse;
use ApiKit\Http\Response;
use Throwable;

final class ExceptionHandler
{
    /** @var bool */
    private $debug = false;

    public function setDebug(bool $debug): void
    {
        $this->debug = $debug;
    }

    public function render(Throwable $exception): Response
    {
        $status = $exception instanceof HttpException
            ? $exception->status()
            : 500;

        $code = $exception instanceof HttpException
            ? $exception->errorCode()
            : 'SERVER_ERROR';

        $message = $status === 500 && !$this->debug
            ? 'Внутренняя ошибка сервера'
            : $exception->getMessage();

        $error = [
            'code' => $code,
            'message' => $message,
        ];

        if ($this->debug) {
            $error['exception'] = get_class($exception);
            $error['file'] = $exception->getFile();
            $error['line'] = $exception->getLine();
        }

        return new JsonResponse(['error' => $error], $status);
    }
}
