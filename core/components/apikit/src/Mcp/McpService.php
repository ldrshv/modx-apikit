<?php

declare(strict_types=1);

namespace ApiKit\Mcp;

use stdClass;
use Throwable;

/**
 * Базовый класс MCP-сервиса.
 *
 * Сервис описывает себя (info), отдаёт список инструментов (tools)
 * и выполняет инструмент (call). Протокол MCP обрабатывает McpServer.
 */
abstract class McpService
{
    private const READ_ONLY_ANNOTATIONS = [
        'readOnlyHint' => true,
        'destructiveHint' => false,
        'idempotentHint' => true,
        'openWorldHint' => false,
    ];

    /** @var array<string, mixed> */
    protected $config;

    /**
     * @param array<string, mixed> $config Полный config.php приложения
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Сведения о сервере: name, version, instructions
     * и необязательный protocolVersion по умолчанию.
     */
    abstract public function info(): array;

    /**
     * Список инструментов в формате MCP tools/list.
     */
    abstract public function tools(): array;

    /**
     * Выполняет инструмент и возвращает данные результата.
     * Любое исключение превращается в ответ с isError = true.
     */
    abstract protected function call(string $name, array $arguments): array;

    /**
     * Код ошибки для ответа инструмента.
     */
    protected function errorCode(Throwable $exception): string
    {
        return 'REQUEST_ERROR';
    }

    final public function callTool(string $name, array $arguments): array
    {
        try {
            return $this->result($this->call($name, $arguments), false);
        } catch (Throwable $exception) {
            return $this->result(
                [
                    'error_code' => $this->errorCode($exception),
                    'message' => $exception->getMessage(),
                ],
                true
            );
        }
    }

    /**
     * Описание инструмента. По умолчанию инструмент только читает данные.
     */
    protected function tool(
        string $name,
        string $description,
        array $properties = [],
        array $required = [],
        array $annotations = []
    ): array {
        return [
            'name' => $name,
            'description' => $description,

            'inputSchema' => [
                'type' => 'object',
                'properties' => $properties === [] ? new stdClass() : $properties,
                'required' => $required,
                'additionalProperties' => false,
            ],

            'annotations' => array_merge(
                self::READ_ONLY_ANNOTATIONS,
                $annotations
            ),
        ];
    }

    /**
     * Настройки сервиса из блока services в config.php.
     */
    protected function settings(string $service): array
    {
        $settings = $this->config['services'][$service] ?? [];

        return is_array($settings) ? $settings : [];
    }

    protected function timezone(): string
    {
        return (string) ($this->config['timezone'] ?? 'Europe/Moscow');
    }

    private function result(array $data, bool $isError): array
    {
        $text = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_INVALID_UTF8_SUBSTITUTE
        );

        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => $text === false ? '{}' : $text,
                ],
            ],

            'structuredContent' => $data === [] ? new stdClass() : $data,
            'isError' => $isError,
        ];
    }
}
