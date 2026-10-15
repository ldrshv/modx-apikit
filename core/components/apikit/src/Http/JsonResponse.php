<?php

declare(strict_types=1);

namespace ApiKit\Http;

use RuntimeException;

final class JsonResponse extends Response
{
    public function __construct($data = null, int $status = 200, array $headers = [])
    {
        $json = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_INVALID_UTF8_SUBSTITUTE
        );

        if ($json === false) {
            throw new RuntimeException('Не удалось сформировать JSON');
        }

        parent::__construct(
            $json,
            $status,
            ['Content-Type' => 'application/json; charset=utf-8'] + $headers
        );
    }
}
