<?php

declare(strict_types=1);

namespace ApiKit\Routing;

final class RouteGroup
{
    /** @var Router */
    private $router;

    /** @var string */
    private $prefix = '';

    /** @var array<int, string> */
    private $middleware = [];

    public function __construct(Router $router)
    {
        $this->router = $router;
    }

    public function prefix(string $prefix): self
    {
        $this->prefix = '/' . trim($prefix, '/');

        return $this;
    }

    public function middleware(string ...$middleware): self
    {
        $this->middleware = array_merge($this->middleware, $middleware);

        return $this;
    }

    public function group(callable $callback): void
    {
        $this->router->pushGroup($this->prefix, $this->middleware);

        try {
            $callback($this->router);
        } finally {
            $this->router->popGroup();
        }
    }
}
