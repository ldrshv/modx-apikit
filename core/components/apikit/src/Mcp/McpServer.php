<?php

declare(strict_types=1);

namespace ApiKit\Mcp;

use ApiKit\Container;
use ApiKit\Exceptions\NotFoundException;
use ApiKit\Exceptions\UnauthorizedException;
use ApiKit\Http\JsonResponse;
use ApiKit\Http\Request;
use ApiKit\Http\Response;
use JsonException;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * MCP-сервер (Streamable HTTP, JSON-ответы).
 *
 * Маршрут: POST /mcp/{name}
 * Сервис и его ключ берутся из config['mcp'][name].
 */
final class McpServer
{
    public const PROTOCOL_VERSIONS = [
        '2025-06-18',
        '2025-03-26',
        '2024-11-05',
    ];

    /** @var Container */
    private $container;

    /** @var string */
    private $protocolVersion;

    public function __construct(Container $container)
    {
        $this->container = $container;
        $this->protocolVersion = self::PROTOCOL_VERSIONS[0];
    }

    public function handle(Request $request, string $name): Response
    {
        $config = $this->container->make('config');
        $settings = $config['mcp'][$name] ?? null;

        if (!is_array($settings)) {
            throw new NotFoundException('MCP-сервер не найден');
        }

        $this->authorize($request, (string) ($settings['api_key'] ?? ''));

        if (isset($config['time_limit']) && function_exists('set_time_limit')) {
            @set_time_limit((int) $config['time_limit']);
        }

        $headerVersion = (string) $request->header('MCP-Protocol-Version', '');

        if (in_array($headerVersion, self::PROTOCOL_VERSIONS, true)) {
            $this->protocolVersion = $headerVersion;
        }

        try {
            $message = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return $this->error(null, -32700, 'Некорректный JSON');
        }

        if (!is_array($message) || $message === [] || $this->isList($message)) {
            return $this->error(null, -32600, 'Некорректный запрос');
        }

        $method = isset($message['method']) && is_string($message['method'])
            ? $message['method']
            : '';

        // Уведомления и ответы клиента: тело ответа не нужно.
        if (
            !array_key_exists('id', $message)
            || ($method === '' && (array_key_exists('result', $message) || array_key_exists('error', $message)))
            || strpos($method, 'notifications/') === 0
        ) {
            return new Response('', 202, $this->headers());
        }

        $id = $message['id'];
        $params = isset($message['params']) && is_array($message['params'])
            ? $message['params']
            : [];

        if ($method === '') {
            return $this->error($id, -32600, 'Некорректный запрос');
        }

        try {
            $service = $this->service($settings, $config);

            switch ($method) {
                case 'initialize':
                    return $this->result($id, $this->initialize($service, $params));

                case 'ping':
                    return $this->result($id, new stdClass());

                case 'tools/list':
                    return $this->result($id, ['tools' => $service->tools()]);

                case 'tools/call':
                    $tool = isset($params['name']) && is_string($params['name'])
                        ? $params['name']
                        : '';

                    $arguments = isset($params['arguments']) && is_array($params['arguments'])
                        ? $params['arguments']
                        : [];

                    return $this->result($id, $service->callTool($tool, $arguments));

                default:
                    return $this->error($id, -32601, 'Метод не найден');
            }
        } catch (Throwable $exception) {
            return $this->error($id, -32603, $exception->getMessage());
        }
    }

    private function initialize(McpService $service, array $params): array
    {
        $info = $service->info();
        $requested = isset($params['protocolVersion']) && is_string($params['protocolVersion'])
            ? $params['protocolVersion']
            : '';

        if (in_array($requested, self::PROTOCOL_VERSIONS, true)) {
            $this->protocolVersion = $requested;
        } elseif (
            isset($info['protocolVersion'])
            && in_array($info['protocolVersion'], self::PROTOCOL_VERSIONS, true)
        ) {
            $this->protocolVersion = $info['protocolVersion'];
        }

        $result = [
            'protocolVersion' => $this->protocolVersion,

            'capabilities' => [
                'tools' => [
                    'listChanged' => false,
                ],
            ],

            'serverInfo' => [
                'name' => (string) ($info['name'] ?? 'mcp'),
                'version' => (string) ($info['version'] ?? '1.0.0'),
            ],
        ];

        if (!empty($info['instructions'])) {
            $result['instructions'] = (string) $info['instructions'];
        }

        return $result;
    }

    private function authorize(Request $request, string $expected): void
    {
        $expected = trim($expected);
        $provided = $request->bearerToken();

        if (
            $expected === ''
            || $provided === null
            || !hash_equals($expected, $provided)
        ) {
            throw new UnauthorizedException('Неверный ключ доступа');
        }
    }

    private function service(array $settings, array $config): McpService
    {
        $class = (string) ($settings['class'] ?? '');

        if ($class === '' || !class_exists($class) || !is_subclass_of($class, McpService::class)) {
            throw new RuntimeException('Класс MCP-сервиса не найден: ' . $class);
        }

        return new $class($config);
    }

    private function result($id, $result): JsonResponse
    {
        return new JsonResponse(
            [
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => $result,
            ],
            200,
            $this->headers()
        );
    }

    private function error($id, int $code, string $message): JsonResponse
    {
        return new JsonResponse(
            [
                'jsonrpc' => '2.0',
                'id' => $id,
                'error' => [
                    'code' => $code,
                    'message' => $message,
                ],
            ],
            200,
            $this->headers()
        );
    }

    private function headers(): array
    {
        return ['MCP-Protocol-Version' => $this->protocolVersion];
    }

    private function isList(array $value): bool
    {
        return array_keys($value) === range(0, count($value) - 1);
    }
}
