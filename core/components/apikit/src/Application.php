<?php

declare(strict_types=1);

namespace ApiKit;

use ApiKit\Exceptions\ExceptionHandler;
use ApiKit\Http\Request;
use ApiKit\Http\Response;
use ApiKit\Routing\Router;
use Throwable;

final class Application
{
    /** @var Container */
    private $container;

    /** @var Router */
    private $router;

    /** @var ExceptionHandler */
    private $exceptionHandler;

    public function __construct()
    {
        $this->container = new Container();
        $this->container->instance(self::class, $this);
        $this->container->instance(Container::class, $this->container);

        $this->router = new Router($this->container);
        $this->exceptionHandler = new ExceptionHandler();

        $this->container->instance(Router::class, $this->router);
        $this->container->instance(ExceptionHandler::class, $this->exceptionHandler);
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function debug(bool $debug): self
    {
        $this->exceptionHandler->setDebug($debug);

        return $this;
    }

    public function handle(Request $request): Response
    {
        try {
            return $this->router->dispatch($request);
        } catch (Throwable $exception) {
            return $this->exceptionHandler->render($exception);
        }
    }
}
