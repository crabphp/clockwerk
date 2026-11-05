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
