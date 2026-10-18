<?php

declare(strict_types=1);

namespace Crab\Clockwerk;

use Crab\Contracts\Routing\Router;

/**
 * Finds the pattern of the route a request matched, so that requests are
 * counted by route, "GET /users/{id}", and not by every address asked
 * for. The router does not say which route it chose, so this reads the
 * route list and matches the path again, remembering each answer.
 */
final class RouteNames
{
    /**
     * Routes without parameters, by method and path.
     *
     * @var array<string, array<string, true>>
     */
    private array $static = [];
    /**
     * Routes with parameters, by method: an expression and its pattern.
     *
     * @var array<string, list<array{string, string}>>
     */
    private array $dynamic = [];
    /**
     * Paths already matched, by method.
     *
     * @var array<string, array<string, ?string>>
     */
    private array $seen = [];
    private int $remembered = 0;

    public function __construct(Router $router)
    {
        foreach ($router->routes() as $route) {
            if (!str_contains($route->pattern, '{')) {
                $this->static[$route->method][$route->pattern] = true;
                continue;
            }
            $this->dynamic[$route->method][] = [self::expression($route->pattern), $route->pattern];
        }
    }

    /**
     * @return string|null The pattern of the route for this method and path; null when there is none.
     */
    public function of(string $method, string $path): ?string
    {
        if (isset($this->static[$method][$path])) {
            return $path;
        }
        if (array_key_exists($path, $this->seen[$method] ?? [])) {
            return $this->seen[$method][$path];
        }
        $pattern = null;
        foreach ($this->dynamic[$method] ?? [] as [$expression, $candidate]) {
            if (preg_match($expression, $path) !== 1) {
                continue;
            }

            $pattern = $candidate;
            break;
        }
        // As the router does: a HEAD with no route of its own is answered by the GET route.
        if ($pattern === null && $method === 'HEAD') {
            return $this->of('GET', $path);
        }
        // Bounded, so that a flood of made-up addresses cannot fill the worker's memory.
        if ($this->remembered < 5000) {
            ++$this->remembered;
            $this->seen[$method][$path] = $pattern;
        }
        return $pattern;
    }

    /**
     * A pattern as a regular expression: {name} is one segment, and
     * {name:expression} is what its expression says.
     */
    private static function expression(string $pattern): string
    {
        preg_match_all('/\{\w+(?::((?:[^{}]|\{[^{}]*\})+))?\}/', $pattern, $parameters, PREG_SET_ORDER);
        $literals = preg_split('/\{\w+(?::(?:[^{}]|\{[^{}]*\})+)?\}/', $pattern) ?: [];
        $expression = '';
        foreach ($literals as $index => $literal) {
            $expression .= preg_quote($literal, '~');
            if (isset($parameters[$index])) {
                // The expression is the route's own; only the delimiter used here must not end it early.
                $expression .= '(?:' . str_replace('~', '\\~', $parameters[$index][1] ?? '[^/]+') . ')';
            }
        }
        return '~^' . $expression . '$~D';
    }
}
