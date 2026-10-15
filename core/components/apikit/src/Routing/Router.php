<?php

declare(strict_types=1);

namespace ApiKit\Routing;

use ApiKit\Container;
use ApiKit\Exceptions\MethodNotAllowedException;
use ApiKit\Exceptions\NotFoundException;
use ApiKit\Http\JsonResponse;
use ApiKit\Http\Request;
use ApiKit\Http\Response;
use ApiKit\Middleware\MiddlewareInterface;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;

final class Router
{
    /** @var Container */
    private $container;

    /** @var array<int, Route> */
    private $routes = [];

    /** @var array<int, array{prefix:string,middleware:array}> */
    private $groups = [];

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function get(string $uri, $action): Route
    {
        return $this->add(['GET'], $uri, $action);
    }

    public function post(string $uri, $action): Route
    {
        return $this->add(['POST'], $uri, $action);
    }

    public function put(string $uri, $action): Route
    {
        return $this->add(['PUT'], $uri, $action);
    }

    public function patch(string $uri, $action): Route
    {
        return $this->add(['PATCH'], $uri, $action);
    }

    public function delete(string $uri, $action): Route
    {
        return $this->add(['DELETE'], $uri, $action);
    }

    public function prefix(string $prefix): RouteGroup
    {
        return (new RouteGroup($this))->prefix($prefix);
    }

    public function middleware(string ...$middleware): RouteGroup
    {
        return (new RouteGroup($this))->middleware(...$middleware);
    }

    public function pushGroup(string $prefix, array $middleware): void
    {
        $this->groups[] = [
            'prefix' => $prefix,
            'middleware' => $middleware,
        ];
    }

    public function popGroup(): void
    {
        array_pop($this->groups);
    }

    public function dispatch(Request $request): Response
    {
        $pathFound = false;

        foreach ($this->routes as $route) {
            $parameters = $route->match($request->path());

            if ($parameters === null) {
                continue;
            }

            $pathFound = true;

            if (!in_array($request->method(), $route->methods(), true)) {
                continue;
            }

            $request->setRouteParameters($parameters);

            return $this->runRoute($route, $request);
        }

        if ($pathFound) {
            throw new MethodNotAllowedException();
        }

        throw new NotFoundException();
    }

    private function add(array $methods, string $uri, $action): Route
    {
        $prefix = '';
        $middleware = [];

        foreach ($this->groups as $group) {
            $prefix .= $group['prefix'];
            $middleware = array_merge($middleware, $group['middleware']);
        }

        $fullUri = $prefix . '/' . ltrim($uri, '/');
        $route = new Route($methods, $fullUri, $action);

        if ($middleware !== []) {
            $route->middleware(...$middleware);
        }

        $this->routes[] = $route;

        return $route;
    }

    private function runRoute(Route $route, Request $request): Response
    {
        $destination = function (Request $request) use ($route): Response {
            return $this->invokeAction($route->action(), $request);
        };

        $pipeline = array_reduce(
            array_reverse($route->middlewareList()),
            function (callable $next, string $middleware): callable {
                return function (Request $request) use ($middleware, $next): Response {
                    $instance = $this->container->make($middleware);

                    if (!$instance instanceof MiddlewareInterface) {
                        throw new RuntimeException(
                            $middleware . ' должен реализовывать MiddlewareInterface'
                        );
                    }

                    return $instance->handle($request, $next);
                };
            },
            $destination
        );

        return $pipeline($request);
    }

    private function invokeAction($action, Request $request): Response
    {
        if (!is_array($action) || count($action) !== 2) {
            throw new RuntimeException('Обработчик маршрута должен иметь вид [Controller::class, method]');
        }

        $controller = is_string($action[0])
            ? $this->container->make($action[0])
            : $action[0];

        $method = (string) $action[1];
        $reflection = new ReflectionMethod($controller, $method);
        $arguments = [];

        foreach ($reflection->getParameters() as $parameter) {
            $type = $parameter->getType();
            $name = $parameter->getName();

            if (
                $type instanceof ReflectionNamedType
                && !$type->isBuiltin()
                && $type->getName() === Request::class
            ) {
                $arguments[] = $request;
                continue;
            }

            $routeValue = $request->route($name);

            if ($routeValue !== null) {
                $arguments[] = $routeValue;
                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
                continue;
            }

            throw new RuntimeException('Не удалось передать параметр $' . $name);
        }

        $result = $reflection->invokeArgs($controller, $arguments);

        if ($result instanceof Response) {
            return $result;
        }

        if (is_array($result) || is_object($result) || $result === null) {
            return new JsonResponse($result);
        }

        throw new RuntimeException('Контроллер должен вернуть Response, массив, объект или null');
    }
}
