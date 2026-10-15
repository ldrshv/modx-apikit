<?php

declare(strict_types=1);

namespace ApiKit\Exceptions;

use RuntimeException;
use Throwable;

class HttpException extends RuntimeException
{
    /** @var int */
    private $status;

    /** @var string */
    private $errorCode;

    public function __construct(
        int $status,
        string $message,
        string $errorCode,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->status = $status;
        $this->errorCode = $errorCode;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
