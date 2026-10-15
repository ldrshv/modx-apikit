<?php

declare(strict_types=1);

namespace ApiKit\Routing;

final class Route
{
    /** @var array<int, string> */
    private $methods;

    /** @var string */
    private $uri;

    /** @var mixed */
    private $action;

    /** @var array<int, string> */
    private $middleware = [];

    public function __construct(array $methods, string $uri, $action)
    {
        $this->methods = array_map('strtoupper', $methods);
        $this->uri = $uri === '/' ? '/' : '/' . trim($uri, '/');
        $this->action = $action;
    }

    public function methods(): array
    {
        return $this->methods;
    }

    public function action()
    {
        return $this->action;
    }

    public function middleware(string ...$middleware): self
    {
        $this->middleware = array_merge($this->middleware, $middleware);

        return $this;
    }

    public function middlewareList(): array
    {
        return $this->middleware;
    }

    public function match(string $path): ?array
    {
        $parameters = [];
        $pattern = '';
        $offset = 0;

        preg_match_all(
            '/\{([A-Za-z_][A-Za-z0-9_]*)\}/',
            $this->uri,
            $matches,
            PREG_OFFSET_CAPTURE
        );

        foreach ($matches[0] as $index => $placeholder) {
            $value = $placeholder[0];
            $position = $placeholder[1];

            $pattern .= preg_quote(
                substr($this->uri, $offset, $position - $offset),
                '~'
            );
            $pattern .= '([^/]+)';
            $parameters[] = $matches[1][$index][0];
            $offset = $position + strlen($value);
        }

        $pattern .= preg_quote(substr($this->uri, $offset), '~');

        if (preg_match('~^' . $pattern . '$~', $path, $values) !== 1) {
            return null;
        }

        array_shift($values);

        return $parameters === []
            ? []
            : array_combine($parameters, array_map('rawurldecode', $values));
    }
}
