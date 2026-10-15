<?php

declare(strict_types=1);

namespace ApiKit\Http;

use ApiKit\Exceptions\BadRequestException;
use JsonException;

final class Request
{
    /** @var string */
    private $method;

    /** @var string */
    private $uri;

    /** @var string */
    private $basePath;

    /** @var array<string, mixed> */
    private $query;

    /** @var array<string, string> */
    private $headers;

    /** @var string */
    private $body;

    /** @var array<string, mixed>|null */
    private $json;

    /** @var array<string, string> */
    private $routeParameters = [];

    public function __construct(
        string $method,
        string $uri,
        array $query = [],
        array $headers = [],
        string $body = '',
        string $basePath = ''
    ) {
        $this->method = strtoupper($method);
        $this->uri = $uri;
        $this->query = $query;
        $this->headers = $this->normalizeHeaders($headers);
        $this->body = $body;
        $this->basePath = '/' . trim($basePath, '/');
        $this->json = null;
    }

    public static function capture(string $basePath = ''): self
    {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $name = str_replace('_', '-', substr($key, 5));
                $headers[$name] = (string) $value;
            }
        }

        if (!empty($_SERVER['CONTENT_TYPE'])) {
            $headers['Content-Type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers['Authorization'] = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        if (function_exists('getallheaders')) {
            $nativeHeaders = getallheaders();

            if (is_array($nativeHeaders)) {
                $headers = array_merge($headers, $nativeHeaders);
            }
        }

        return new self(
            (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            (string) ($_SERVER['REQUEST_URI'] ?? '/'),
            $_GET,
            $headers,
            (string) file_get_contents('php://input'),
            $basePath
        );
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        $path = rawurldecode((string) parse_url($this->uri, PHP_URL_PATH));

        if (
            $this->basePath !== '/'
            && ($path === $this->basePath || strpos($path, $this->basePath . '/') === 0)
        ) {
            $path = substr($path, strlen($this->basePath));
        }

        $path = '/' . trim($path, '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    public function query(?string $key = null, $default = null)
    {
        return $key === null ? $this->query : ($this->query[$key] ?? $default);
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function bearerToken(): ?string
    {
        $authorization = $this->header('Authorization');

        if (
            $authorization === null
            || preg_match('/^Bearer\s+(.+)$/i', trim($authorization), $matches) !== 1
        ) {
            return null;
        }

        return trim($matches[1]);
    }

    public function body(): string
    {
        return $this->body;
    }

    public function json(?string $key = null, $default = null)
    {
        if ($this->json === null) {
            if ($this->body === '') {
                $this->json = [];
            } else {
                try {
                    $decoded = json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException $exception) {
                    throw new BadRequestException('Некорректный JSON', $exception);
                }

                if (!is_array($decoded)) {
                    throw new BadRequestException('JSON должен содержать объект или массив');
                }

                $this->json = $decoded;
            }
        }

        return $key === null ? $this->json : ($this->json[$key] ?? $default);
    }

    public function input(?string $key = null, $default = null)
    {
        $input = array_merge($this->query, $this->json());

        return $key === null ? $input : ($input[$key] ?? $default);
    }

    public function route(?string $key = null, $default = null)
    {
        return $key === null
            ? $this->routeParameters
            : ($this->routeParameters[$key] ?? $default);
    }

    public function setRouteParameters(array $parameters): void
    {
        $this->routeParameters = $parameters;
    }

    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            $normalized[strtolower((string) $name)] = trim((string) $value);
        }

        return $normalized;
    }
}
