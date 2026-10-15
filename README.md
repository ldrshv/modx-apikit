# APIKit

Минимальный API-каркас для MODX Revolution 2 и PHP 7.4 со встроенной поддержкой MCP.

## Структура

- `/api` — публичная точка входа.
- `/core/components/apikit` — ядро: роутер, контейнер, Request/Response, MCP.
- `/core/app` — конфигурация, маршруты, контроллеры и сервисы сайта.

```
core/components/apikit/src/Mcp/
├── McpServer.php     — протокол MCP (JSON-RPC), авторизация, маршрут /mcp/{name}
└── McpService.php    — базовый класс MCP-сервиса

core/app/
├── config.php
├── routes/api.php
├── Http/Controllers/HealthController.php
└── Services/
    ├── Metrika/      — MetrikaClient, MetrikaResolver, MetrikaMcp
    └── Webmaster/    — WebmasterClient, WebmasterResolver, WebmasterMcp
```

## Установка

1. Скопируйте содержимое архива в корень сайта с сохранением структуры.
2. Заполните `/core/app/config.php`: OAuth-токены в `services`, ключи доступа в `mcp`.
3. Не коммитьте `config.php` с реальными токенами.

Требования: PHP 7.4+, расширения `curl`, `json`, желательно `intl` (кириллические домены).

## Проверка

```
GET  /api/health
POST /api/mcp/metrika     Authorization: Bearer <mcp.metrika.api_key>
POST /api/mcp/webmaster   Authorization: Bearer <mcp.webmaster.api_key>
```

Пример запроса:

```
curl -X POST https://site.ru/api/mcp/metrika \
  -H "Authorization: Bearer KEY" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
```

## MCP

Поддерживаются `initialize`, `ping`, `tools/list`, `tools/call` и уведомления (ответ 202).
Версии протокола: 2025-06-18, 2025-03-26, 2024-11-05.

Ошибки протокола возвращаются в формате JSON-RPC (-32700, -32600, -32601, -32603).
Ошибки инструментов — как результат с `isError: true` и `error_code`.

## Новый MCP-сервер

1. Создайте `core/app/Services/Name/` с клиентом API и классом `NameMcp extends ApiKit\Mcp\McpService`.
2. Реализуйте три метода: `info()`, `tools()`, `call()`. При необходимости — `errorCode()`.
3. Добавьте настройки в `config.php`: блок `services.name` и блок `mcp.name` с `class` и `api_key`.

Сервер станет доступен по адресу `/api/mcp/name`. Маршруты и ядро менять не нужно.

Описание инструмента: `$this->tool($name, $description, $properties, $required)`.
По умолчанию инструмент помечается как read-only; для записи передайте пятым аргументом
`['readOnlyHint' => false, 'destructiveHint' => true]`.

## Обычный API

Контроллер в `core/app/Http/Controllers`, маршрут в `core/app/routes/api.php`.
Обработчик задаётся как пара `Controller::class` и имя метода.
Поддерживаются GET, POST, PUT, PATCH, DELETE, параметры `/items/{id}`, группы с префиксом и middleware.
