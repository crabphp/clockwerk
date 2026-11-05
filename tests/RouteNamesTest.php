<?php

declare(strict_types=1);

namespace Crab\Clockwerk\Tests;

use Crab\App;
use Crab\Clockwerk\ClockwerkPlugin;
use Crab\Clockwerk\Recorder;
use Crab\Clockwerk\RouteNames;
use Crab\Clockwerk\Store;
use Crab\Config\Config;
use Crab\Http\Request;
use Crab\Routing\Router;
use PHPUnit\Framework\TestCase;

final class RouteNamesTest extends TestCase
{
    public function testApplicationRoutesUnderTheDashboardPrefixAreStillCounted(): void
    {
        foreach (['/' => '/api/users', '/clockwerk' => '/clockwerk/reports'] as $mount => $path) {
            $app = new App(new Config(['clockwerk' => ['path' => $mount]]));
            $app->register(ClockwerkPlugin::class);
            $metrics = [];
            $store = $this->createStub(Store::class);
            $store
                ->method('metrics')
                ->willReturnCallback(static function (string $worker, array $rows) use (&$metrics): void {
                    $metrics = $rows;
                });
            $app->container->instance(Store::class, $store);
            $app->router->get($path, static fn(): string => 'application');
            self::assertSame(200, $app->handle(new Request('GET', $path))->getStatusCode());
            $app->container->get(Recorder::class)->flush('worker');
            self::assertCount(1, $metrics);
            self::assertSame('GET ' . $path, $metrics[0]['name']);
        }
    }

    public function testMadeUpHttpMethodsCannotCreateUnboundedMetricNames(): void
    {
        $app = new App(new Config());
        $app->register(ClockwerkPlugin::class);
        $metrics = [];
        $store = $this->createStub(Store::class);
        $store
            ->method('metrics')
            ->willReturnCallback(static function (string $worker, array $rows) use (&$metrics): void {
                $metrics = $rows;
            });
        $app->container->instance(Store::class, $store);
        for ($i = 0; $i < 100; ++$i) {
            self::assertSame(404, $app->handle(new Request('METHOD' . $i, '/missing'))->getStatusCode());
        }
        $app->container->get(Recorder::class)->flush('worker');
        self::assertCount(1, $metrics);
        self::assertSame(100, $metrics[0]['runs']);
        self::assertSame('OTHER (no route)', $metrics[0]['name']);
    }

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
