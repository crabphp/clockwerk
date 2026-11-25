<?php

declare(strict_types=1);

namespace Crab\Clockwerk\Tests;

use Crab\App;
use Crab\Clockwerk\ClockwerkPlugin;
use Crab\Clockwerk\Store;
use Crab\Config\Config;
use Crab\Engine\Crab;
use Crab\Engine\EventLoop;
use Crab\Engine\Process\Worker;
use PHPUnit\Framework\TestCase;

final class WorkerRecordingTest extends TestCase
{
    public function testAWorkerThatAskedToBeReplacedSaysSoAsItStops(): void
    {
        $loop = $this->createStub(EventLoop::class);
        $loop->method('repeat')->willReturn('timer');
        $events = [];
        $store = $this->createStub(Store::class);
        $store
            ->method('events')
            ->willReturnCallback(static function (array $written) use (&$events): void {
                $events = [...$events, ...$written];
            });
        $app = new App(new Config());
        $plugin = new ClockwerkPlugin();
        $plugin->register($app->container);
        $app->container->instance(Store::class, $store);
        $quiet = new Worker($loop, new Crab('http'), 0, ['tcp' => []], 1.0);
        $plugin->start($quiet, $app->container);
        $plugin->stop($quiet, $app->container);
        self::assertSame([], $events, 'A worker stopped for any other reason records nothing.');
        if (!method_exists(Crab::class, 'maxMemory')) {
            // Before Crab 0.7.6 a worker never says it is being replaced, so nothing more is recorded.
            return;
        }

        $worker = new Worker($loop, new Crab('http')->maxMemory(1), 0, ['tcp' => []], 1.0);
        $plugin->start($worker, $app->container);
        $worker->count('requests');
        $plugin->stop($worker, $app->container);

        self::assertCount(1, $events);
        self::assertSame('replaced', $events[0]['kind']);
        self::assertSame('http worker ' . getmypid() . ' replaced for its memory', $events[0]['title']);
        self::assertSame('http', $events[0]['detail']['group']);
        self::assertSame('memory', $events[0]['detail']['reason']);
        self::assertSame(getmypid(), $events[0]['detail']['pid']);
        self::assertGreaterThan(0, $events[0]['detail']['memory']);
    }

    public function testAWaitingHeartbeatDoesNotStartMoreWritesOnLaterTicks(): void
    {
        $deferred = $timers = [];
        $loop = $this->createStub(EventLoop::class);
        $loop->method('defer')->willReturnCallback(static function (\Closure $callback) use (&$deferred): string {
            $deferred[] = $callback;
            return 'task-' . count($deferred);
        });
        $loop->method('repeat')->willReturnCallback(static function (float $interval, \Closure $callback) use (
            &$timers,
        ): string {
            $timers[] = $callback;
            return 'timer';
        });
        $writes = 0;
        $store = $this->createStub(Store::class);
        $store
            ->method('beat')
            ->willReturnCallback(static function () use (&$writes): void {
                ++$writes;
                \Fiber::suspend();
            });
        $app = new App(new Config());
        $plugin = new ClockwerkPlugin();
        $plugin->register($app->container);
        $app->container->instance(Store::class, $store);
        $worker = new Worker($loop, new Crab('http'), 0, ['tcp' => []], 1.0);
        $plugin->start($worker, $app->container);
        $first = new \Fiber($deferred[0]);
        $first->start();
        for ($tick = 0; $tick < 10; ++$tick) {
            $timers[0]();
        }
        $queuedWhileWaiting = count($deferred);
        $first->resume();
        $timers[0]();

        self::assertSame(1, $writes);
        self::assertSame(1, $queuedWhileWaiting);
        self::assertCount(2, $deferred, 'A completed write allows the next tick to write.');
        $plugin->stop($worker, $app->container);
    }
}
