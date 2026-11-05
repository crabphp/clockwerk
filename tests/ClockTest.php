<?php

declare(strict_types=1);

namespace Crab\Clockwerk\Tests;

use Crab\App;
use Crab\Clockwerk\ClockwerkPlugin;
use Crab\Clockwerk\Dashboard;
use Crab\Clockwerk\Recorder;
use Crab\Clockwerk\Store;
use Crab\Config\Config;
use Crab\Contracts\Queue\Store as QueueStore;
use Crab\Contracts\Schedule\Schedule;
use Crab\Http\Request;
use Crab\Schedule\SchedulePlugin;
use Crab\Testing\FrozenClock;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

final class ClockTest extends TestCase
{
    public function testRecordingScheduleAndRetryUseTheApplicationsClock(): void
    {
        $clock = new FrozenClock('2001-01-01 10:00:30 UTC');
        $timestamp = $clock->now()->getTimestamp();
        $app = new App(new Config([
            'clockwerk' => ['token' => 'secret'],
            'schedule' => ['timezone' => 'Asia/Ho_Chi_Minh'],
            'log' => ['path' => 'php://memory'],
        ]));
        $app->container->instance(ClockInterface::class, $clock);
        $queue = $this->createMock(QueueStore::class);
        $queue->method('counts')->willReturn(['pending' => 0, 'delayed' => 0, 'processing' => 0, 'oldest' => null]);
        $queue->expects(self::once())->method('retry')->with('job', $timestamp)->willReturn(1);
        $app->container->instance(QueueStore::class, $queue);
        $app->register(SchedulePlugin::class, ClockwerkPlugin::class);
        $metrics = $events = [];
        $store = $this->createStub(Store::class);
        $store
            ->method('metrics')
            ->willReturnCallback(static function (string $worker, array $rows) use (&$metrics): void {
                $metrics = $rows;
            });
        $store
            ->method('events')
            ->willReturnCallback(static function (array $rows) use (&$events): void {
                $events = $rows;
            });
        $app->container->instance(Store::class, $store);
        $recorder = $app->container->get(Recorder::class);
        $recorder->measure('request', 'GET /', 1, false);
        $recorder->exception(new \RuntimeException('test'), 'GET /');
        $recorder->flush('worker');
        $app
            ->container
            ->get(Schedule::class)
            ->call(static fn() => null)
            ->daily('18:00');
        $state = $app->container->get(Dashboard::class)->state();
        $retry = $app->handle(
            new Request(
                'POST',
                '/clockwerk/api/jobs/retry',
                [
                    'Authorization' => 'Bearer secret',
                    'Content-Type' => 'application/json',
                ],
                '{"id":"job"}',
            ),
        );

        self::assertSame(200, $retry->getStatusCode());
        self::assertSame($timestamp, $state['now']);
        self::assertSame($timestamp - 30, $metrics[0]['bucket']);
        self::assertSame($timestamp, $events[0]['at']);
        self::assertSame(
            new \DateTimeImmutable('2001-01-01 11:00:00 UTC')->getTimestamp(),
            $state['tasks']['rows'][0]['next'],
        );
    }
}
