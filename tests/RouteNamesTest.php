<?php

declare(strict_types=1);

namespace Crab\Clockwerk\Tests;

use Crab\Clockwerk\RouteNames;
use Crab\Http\Request;
use Crab\Routing\Router;
use PHPUnit\Framework\TestCase;

final class RouteNamesTest extends TestCase
{
    public function testEncodedStaticRoutesKeepTheirNameAndTheirPriorityOverDynamicRoutes(): void
    {
        $router = new Router();
        $router->get('/caf%C3%A9', static fn(): string => 'encoded');
        $router->get('/café', static fn(): string => 'decoded');
        $router->get('/{slug}', static fn(string $slug): string => 'dynamic');
        $names = new RouteNames($router);
        foreach (['/caf%C3%A9' => '/caf%C3%A9', '/caf%c3%a9' => '/café'] as $path => $pattern) {
            self::assertSame($pattern, $names->of('GET', $path));
            self::assertSame($pattern, $names->of('HEAD', $path));
        }
        self::assertSame('decoded', (string) $router->handle(new Request('GET', '/caf%c3%a9'))->getBody());
    }
}
