<?php

declare(strict_types=1);

/*
 * Шаблон настроек приложения.
 *
 * 1. Скопируйте этот файл в config.php в той же папке.
 * 2. Заполните токены и ключи в config.php.
 *
 * config.php исключён из Git. Реальные токены и ключи
 * храните только в нём, в этом файле оставляйте пустые значения.
 */

return [
    'debug' => false,
    'timezone' => 'Europe/Moscow',

    // Лимит времени выполнения запроса MCP, секунд (полные отчёты долгие)
    'time_limit' => 300,

    // Доступы к внешним сервисам
    'services' => [
        'metrika' => [
            'oauth_token' => '',
            'page_size' => 10000,
            'connect_timeout' => 10,
            'timeout' => 90,
            'max_retries' => 3,

            // Поиск счётчика в HTML сайта
            'resolver' => [
                'connect_timeout' => 5,
                'timeout' => 15,
                'max_redirects' => 5,
                'max_bytes' => 2097152,
            ],
        ],

        'webmaster' => [
            'oauth_token' => '',
            'connect_timeout' => 10,
            'timeout' => 90,
            'max_retries' => 3,
        ],
    ],

    // MCP-серверы: адрес /api/mcp/{ключ}, у каждого свой Bearer-ключ
    'mcp' => [
        'metrika' => [
            'class' => App\Services\Metrika\MetrikaMcp::class,
            'api_key' => '',
        ],

        'webmaster' => [
            'class' => App\Services\Webmaster\WebmasterMcp::class,
            'api_key' => '',
        ],
    ],
];
