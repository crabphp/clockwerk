<?php

declare(strict_types=1);

namespace Crab\Clockwerk\Tests;

use Crab\Clockwerk\Recorder;
use Crab\Clockwerk\Store;
use PHPUnit\Framework\TestCase;

final class RecordingTest extends TestCase
{
    public function testAnOlderFlushCannotOverwriteNewerCountsOrLoseEventsRecordedWhileWaiting(): void
    {
        $written = [];
        $events = [];
        $store = $this->createStub(Store::class);
        $store
            ->method('metrics')
            ->willReturnCallback(static function (string $worker, array $rows) use (&$written): void {
                if (\Fiber::getCurrent() !== null) {
                    \Fiber::suspend();
                }
                foreach ($rows as $row) {
                    $written[$row['name']] = $row['runs'];
                }
            });
        $store
            ->method('events')
            ->willReturnCallback(static function (array $rows) use (&$events): void {
                array_push($events, ...$rows);
            });
        $recorder = new Recorder($store);
        $recorder->measure('request', 'GET /orders', 1, false);
        $first = new \Fiber(static fn() => $recorder->flush('worker'));
        $first->start();
        $recorder->measure('request', 'GET /orders', 2, false);
        $recorder->exception(new \RuntimeException('while writing'), 'GET /orders');
        $recorder->flush('worker');
        $first->resume();
        $recorder->flush('worker');

        self::assertSame(['GET /orders' => 2], $written);
        self::assertCount(1, $events);
        self::assertSame('while writing', $events[0]['detail']['message']);
    }

    public function testAFlushFailureDoesNotPreventLaterWrites(): void
    {
        $calls = 0;
        $written = [];
        $store = $this->createStub(Store::class);
        $store
            ->method('metrics')
            ->willReturnCallback(static function (string $worker, array $rows) use (&$calls, &$written): void {
                if (++$calls === 1) {
                    throw new \RuntimeException('offline');
                }
                $written = $rows;
            });
        $recorder = new Recorder($store);
        $recorder->measure('request', 'GET /orders', 1, false);
        try {
            $recorder->flush('worker');
        } catch (\RuntimeException $error) {
            self::assertSame('offline', $error->getMessage());
        }
        $recorder->measure('request', 'GET /orders', 1, false);
        $recorder->flush('worker');
        self::assertSame(2, $written[0]['runs']);
    }
}
