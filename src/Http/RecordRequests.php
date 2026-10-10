<?php

declare(strict_types=1);

namespace Crab\Clockwerk\Http;

use Crab\Contracts\Config\Config;
use Crab\Contracts\Routing\Router;
use Crab\Http\HttpException;
use Crab\Clockwerk\Recorder;
use Crab\Clockwerk\RouteNames;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Counts every request by its route, how long it took and whether it
 * failed, and keeps what was thrown. It sits inside the error handler,
 * so it sees an exception before that turns it into a 500. The
 * dashboard's own requests are not counted.
 */
final class RecordRequests implements MiddlewareInterface
{
    private ?RouteNames $routes = null;
    private readonly string $own;

    public function __construct(private readonly Recorder $recorder, private readonly Router $router, Config $config)
    {
        $this->own = rtrim((string) $config->get('clockwerk.path'), '/');
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if ($path === $this->own || str_starts_with($path, $this->own . '/')) {
            return $handler->handle($request);
        }
        $started = hrtime(true);
        try {
            $response = $handler->handle($request);
        } catch (\Throwable $error) {
            $name = $this->name($request->getMethod(), $path);
            // An answer the action chose, such as a 404, is not a failure of the application.
            $chosen = $error instanceof HttpException && $error->status < 500;
            $this->recorder->measure('request', $name, (hrtime(true) - $started) / 1e6, !$chosen);
            if (!$chosen) {
                $this->recorder->exception($error, $name);
            }
            throw $error;
        }
        $this->recorder->measure('request', $this->name($request->getMethod(), $path), (hrtime(true) - $started) / 1e6, $response->getStatusCode() >= 500);
        return $response;
    }

    private function name(string $method, string $path): string
    {
        // Read on the first request: by then every provider has added its routes.
        $this->routes ??= new RouteNames($this->router);
        return $method . ' ' . ($this->routes->of($method, $path === '' ? '/' : $path) ?? '(no route)');
    }
}
