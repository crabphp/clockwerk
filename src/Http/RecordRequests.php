<?php

declare(strict_types=1);

namespace Crab\Clockwerk\Http;

use Crab\Clockwerk\Recorder;
use Crab\Clockwerk\RouteNames;
use Crab\Contracts\Config\Config;
use Crab\Contracts\Http\HttpError;
use Crab\Contracts\Routing\Router;
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
    /**
     * @var array<string, true>
     */
    private readonly array $own;

    public function __construct(
        private readonly Recorder $recorder,
        private readonly Router $router,
        Config $config,
    ) {
        $path = rtrim((string) $config->get('clockwerk.path'), '/');
        $this->own = array_fill_keys([
            $path === '' ? '/' : $path,
            $path . '/api',
            $path . '/api/jobs/retry',
            $path . '/api/jobs/forget',
        ], true);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath() ?: '/';
        // Only its own endpoints: an application may have /api/users beside a dashboard at /.
        $own = isset($this->own[$path]) || str_contains($path, '%') && isset($this->own[rawurldecode($path)]);
        if ($own) {
            return $handler->handle($request);
        }
        $started = hrtime(true);
        try {
            $response = $handler->handle($request);
        } catch (\Throwable $error) {
            $name = $this->name($request->getMethod(), $path);
            // An answer the action chose, such as a 404, is not a failure of the application.
            $chosen = $error instanceof HttpError && $error->status < 500;
            $this->recorder->measure('request', $name, (hrtime(true) - $started) / 1e6, !$chosen);
            if (!$chosen) {
                $this->recorder->exception($error, $name);
            }
            throw $error;
        }
        $this->recorder->measure(
            'request',
            $this->name($request->getMethod(), $path),
            (hrtime(true) - $started) / 1e6,
            $response->getStatusCode() >= 500,
        );
        return $response;
    }

    private function name(string $method, string $path): string
    {
        // Read on the first request: by then every plugin has added its routes.
        $this->routes ??= new RouteNames($this->router);
        $pattern = $this->routes->of($method, $path === '' ? '/' : $path);
        // HTTP permits extension methods: an attacker can invent one on every request.
        if (
            $pattern === null
            && !in_array(
                $method,
                ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'CONNECT', 'TRACE'],
                true,
            )
        ) {
            $method = 'OTHER';
        }
        return $method . ' ' . ($pattern ?? '(no route)');
    }
}
