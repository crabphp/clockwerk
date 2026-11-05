<?php

declare(strict_types=1);

namespace Crab\Clockwerk\Tests;

use Crab\App;
use Crab\Clockwerk\ClockwerkPlugin;
use Crab\Clockwerk\Http\OwnPage;
use Crab\Clockwerk\Store;
use Crab\Config\Config;
use Crab\Contracts\Queue\Store as QueueStore;
use Crab\Http\Request;
use Crab\Http\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;

final class HttpSecurityTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function foreignOrigins(): iterable
    {
        yield 'another port' => ['https://app.example:8443', 'https://app.example:9443'];
        yield 'another scheme' => ['https://app.example', 'http://app.example'];
        yield 'another host' => ['https://app.example', 'https://other.example'];
        yield 'opaque origin' => ['https://app.example', 'null'];
        yield 'userinfo' => ['https://app.example', 'https://user@app.example'];
        yield 'path' => ['https://app.example', 'https://app.example/elsewhere'];
        yield 'invalid port' => ['https://app.example', 'https://app.example:99999'];
    }

    #[DataProvider('foreignOrigins')]
    public function testForeignOriginsCannotRetryOrForgetJobs(string $address, string $origin): void
    {
        $app = $this->app();
        $queue = $this->createMock(QueueStore::class);
        $queue->expects(self::never())->method('retry');
        $queue->expects(self::never())->method('forget');
        $app->container->instance(QueueStore::class, $queue);

        foreach (['retry', 'forget'] as $action) {
            $response = $app->handle(
                new Request(
                    'POST',
                    $address . '/clockwerk/api/jobs/' . $action,
                    [
                        'Authorization' => 'Bearer secret',
                        'Content-Type' => 'application/json',
                        'Origin' => $origin,
                    ],
                    '{"id":"all"}',
                ),
            );
            self::assertSame(403, $response->getStatusCode());
        }
    }

    public function testEquivalentOriginsAndRequestsWithoutBrowserHeadersAreAccepted(): void
    {
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('allowed'));
        $guard = new OwnPage(new Config());
        foreach ([
            ['https://app.example', 'https://APP.example:443'],
            ['http://app.example',  'http://app.example:80'],
            ['https://[::1]:8443',  'https://[::1]:8443'],
            ['https://app.example', ''],
        ] as [$address, $origin]) {
            $response = $guard->process(new Request('POST', $address, [
                'Content-Type' => 'application/json; charset=utf-8',
                'Origin' => $origin,
            ]), $handler);
            self::assertSame(200, $response->getStatusCode());
        }
    }

    public function testThePublicApplicationUrlSuppliesTheOriginBehindATlsProxy(): void
    {
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('allowed'));
        $guard = new OwnPage(new Config(['app' => ['url' => 'https://app.example:8443/base']]));
        $request = new Request('POST', 'http://backend:8080/clockwerk/api/jobs/retry', [
            'Content-Type' => 'application/json',
            'Origin' => 'https://app.example:8443',
        ]);

        self::assertSame(200, $guard->process($request, $handler)->getStatusCode());
        self::assertSame(
            403,
            $guard->process($request->withHeader('Origin', 'http://app.example:8443'), $handler)->getStatusCode(),
        );
    }

    public function testOnlyTheJsonMediaTypeIsAccepted(): void
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $guard = new OwnPage(new Config());
        foreach (['application/jsonp', 'application/json-patch+json', 'text/plain', ''] as $type) {
            self::assertSame(403, $guard->process(new Request('POST', '/', [
                'Content-Type' => $type,
            ]), $handler)->getStatusCode());
        }
    }

    public function testAuthenticationFailuresUseFrameworkProblemsAndAreNotCached(): void
    {
        $app = $this->app();
        foreach ([[], ['Authorization' => 'Bearer wrong']] as $headers) {
            $response = $app->handle(new Request('GET', '/clockwerk/api', ['Accept' => 'application/json'] + $headers));
            self::assertSame(401, $response->getStatusCode());
            self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
            self::assertSame('Bearer', $response->getHeaderLine('WWW-Authenticate'));
            self::assertSame(
                401,
                json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)['error']['status'],
            );
        }
    }

    private function app(): App
    {
        $app = new App(new Config(['clockwerk' => ['token' => 'secret'], 'log' => ['path' => 'php://memory']]));
        $app->container->instance(QueueStore::class, $this->createStub(QueueStore::class));
        $app->register(ClockwerkPlugin::class);
        $app->container->instance(Store::class, $this->createStub(Store::class));
        return $app;
    }
}
