<?php

declare(strict_types=1);

namespace Crab\Clockwerk\Tests;

use Crab\App;
use Crab\Clockwerk\ClockwerkProvider;
use Crab\Clockwerk\Dashboard;
use Crab\Clockwerk\Recorder;
use Crab\Clockwerk\Secrets;
use Crab\Clockwerk\Store;
use Crab\Config\Config;
use Crab\Container\Container;
use Crab\Contracts\Queue\JobRan;
use Crab\Contracts\Queue\Message;
use Crab\Contracts\Queue\Store as QueueStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Exercises recording and dashboard boundaries directly, without a
 * worker or a database, so each review fix has a small regression test.
 */
final class RegressionTest extends TestCase
{
    public function testCustomSecretWordsAreNormalizedAndEmptyWordsHideNothing(): void
    {
        $secrets = new Secrets(['', 'PaSs', 'key.name']);
        $data = ['label' => 'visible', 'PASSWORD' => 'hidden-value', 'nested' => ['key.name' => 'another-value']];

        self::assertSame(['label' => 'visible', 'PASSWORD' => '(hidden)', 'nested' => ['key.name' => '(hidden)']], $secrets->hidden($data));
        self::assertSame('key.name=(hidden)&keyXname=visible', $secrets->scrubbed('key.name=secret&keyXname=visible'));
        self::assertSame($data, (new Secrets([]))->hidden($data));
        self::assertSame('token=visible', (new Secrets([]))->scrubbed('token=visible'));
    }

    public function testSecretArraysHideTheirDescendantsInTextAndKeepShortOrdinaryText(): void
    {
        $secrets = new Secrets(['token']);
        $data = ['token_bundle' => ['nested' => ['value' => 'a/b c'], 'number' => 12345, 'short' => 'abc'], 'public' => 'normal'];

        self::assertSame(['token_bundle' => '(hidden)', 'public' => 'normal'], $secrets->hidden($data));
        self::assertSame('(hidden) (hidden) (hidden) abc normal', $secrets->scrubbed('a/b c a%2Fb%20c 12345 abc normal', $data));
        self::assertSame('X-TOKEN%3D(hidden)&next=yes', $secrets->scrubbed('X-TOKEN%3Dencoded-secret&next=yes'));
    }

    public function testTheRecorderScrubsBothTheRecentTryAndTheStoredException(): void
    {
        $written = [];
        $store = $this->createStub(Store::class);
        $store->method('events')->willReturnCallback(static function (array $events) use (&$written): void {
            $written = $events;
        });
        $recorder = new Recorder($store, hide: ['credential']);
        $message = new Message('job-1', 'mail', 'Send', ['credential' => 'opaque-secret'], 2);
        $recorder->job(new JobRan($message, 0.02, new \RuntimeException('Call /reset/opaque-secret failed'), false));
        $recorder->flush('worker');
        $events = array_column($written, null, 'kind');

        self::assertSame('RuntimeException: Call /reset/(hidden) failed', $events['job']['detail']['error']);
        self::assertSame('retrying', $events['job']['detail']['outcome']);
        self::assertSame('Call /reset/(hidden) failed', $events['exception']['detail']['message']);
        self::assertStringNotContainsString('opaque-secret', json_encode($written, JSON_THROW_ON_ERROR));
    }

    public function testTheProviderPassesCustomSecretWordsToTheRecorder(): void
    {
        $written = [];
        $store = $this->createStub(Store::class);
        $store->method('events')->willReturnCallback(static function (array $events) use (&$written): void {
            $written = $events;
        });
        $app = new App(new Config(['clockwerk' => ['hide' => ['credential']], 'log' => ['path' => 'php://memory']]));
        $app->register(ClockwerkProvider::class);
        $app->container->instance(Store::class, $store);
        $recorder = $app->container->get(Recorder::class);
        $recorder->exception(new \RuntimeException('credential=custom-secret'), 'request');
        $recorder->flush('worker');

        self::assertSame('credential=(hidden)', $written[0]['detail']['message']);
    }

    public function testADashboardWithoutAQueueStillSuppliesAnEmptyJobList(): void
    {
        $jobs = $this->dashboard()->state(60, 'pending')['jobs'];

        self::assertFalse($jobs['installed']);
        self::assertSame([], $jobs['list']);
        self::assertSame('', $jobs['list_state']);
    }

    public function testJobListsAreNotReadUnlessAValidStateIsRequested(): void
    {
        $queue = $this->queue();
        $queue->expects(self::never())->method('jobs');
        $dashboard = $this->dashboard($queue);

        self::assertSame([], $dashboard->state()['jobs']['list']);
        self::assertSame([], $dashboard->state(60, 'done')['jobs']['list']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function jobStates(): iterable
    {
        yield 'pending' => ['pending', 'available_at'];
        yield 'delayed' => ['delayed', 'available_at'];
        yield 'processing' => ['processing', 'reserved_at'];
    }

    /**
     * Each queue deliberately returns a different order. The dashboard
     * must choose the oldest or next due jobs across all queues.
     */
    #[DataProvider('jobStates')]
    public function testJobListsAreSortedAcrossQueuesAndLimitedToOneHundred(string $state, string $by): void
    {
        $queue = $this->queue();
        $queue->method('queues')->willReturn(['default', 'mail']);
        $queue->expects(self::exactly(2))->method('jobs')->willReturnCallback(static function (string $name, string $asked, int $now, int $limit) use ($state, $by): array {
            self::assertSame($state, $asked);
            self::assertSame(100, $limit);
            $jobs = [];
            foreach (range(0, 99) as $i) {
                $time = ($name === 'mail' ? 1000 : 1100) + $i;
                $jobs[] = new Message($name . '-' . $i, $name, 'Send', ['api_key' => 'secret-value', 'label' => $name], 3,
                    availableAt: $by === 'available_at' ? $time : 10_000 - $time,
                    reservedAt: $by === 'reserved_at' ? $time : null);
            }
            return array_reverse($jobs);
        });
        $jobs = $this->dashboard($queue)->state(60, $state)['jobs'];

        self::assertSame($state, $jobs['list_state']);
        self::assertCount(100, $jobs['list']);
        self::assertSame(range(1000, 1099), array_column($jobs['list'], $by));
        self::assertSame(['mail'], array_values(array_unique(array_column($jobs['list'], 'queue'))));
        self::assertSame('mail-0', $jobs['list'][0]['id']);
        self::assertSame(3, $jobs['list'][0]['attempts']);
        self::assertSame(['api_key' => '(hidden)', 'label' => 'mail'], $jobs['list'][0]['data']);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function retentionHours(): iterable
    {
        yield 'configured hours' => [7, 7];
        yield 'minimum one hour' => [0, 1];
    }

    #[DataProvider('retentionHours')]
    public function testTheRecorderPrunesUsingTheConfiguredRetentionHours(int $configured, int $hours): void
    {
        $before = null;
        $store = $this->createStub(Store::class);
        $store->method('prune')->willReturnCallback(static function (int $cutoff) use (&$before): void {
            $before = $cutoff;
        });
        $app = new App(new Config(['clockwerk' => ['keep' => $configured], 'log' => ['path' => 'php://memory']]));
        $app->register(ClockwerkProvider::class);
        $app->container->instance(Store::class, $store);
        $recorder = $app->container->get(Recorder::class);
        $started = time();
        // Workers prune on staggered, randomized writes. Exercise writes
        // until one prunes, with over 160 opportunities and a fixed cap.
        for ($i = 0; $i < 20_000 && $before === null; ++$i) {
            $recorder->flush('worker');
        }

        self::assertNotNull($before, 'No write pruned within the bounded number of opportunities.');
        self::assertGreaterThanOrEqual($started - $hours * 3600, $before);
        self::assertLessThanOrEqual(time() - $hours * 3600, $before);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function messageLimits(): iterable
    {
        yield 'exception' => ['exception', 4000];
        yield 'recent try' => ['job', 500];
        yield 'failed job' => ['failed', 2000];
    }

    #[DataProvider('messageLimits')]
    public function testASecretCrossingTheRecordedMessageLimitIsHiddenBeforeTruncation(string $surface, int $limit): void
    {
        $secret = 'UNIQUE-SECRET-END';
        $prefix = $surface === 'job' ? strlen('RuntimeException: ') : 0;
        $text = str_repeat('x', $limit - $prefix - 5) . $secret;
        if ($surface === 'failed') {
            $queue = $this->queue();
            $queue->expects(self::once())->method('failed')->willReturn([new Message('failed', 'default', 'Send', ['api_key' => $secret], error: $text)]);
            $message = $this->dashboard($queue)->state()['jobs']['failed'][0]['error'];
        } else {
            $message = $this->recordedMessage($surface, $text, $secret);
        }
        self::assertStringNotContainsString('UNIQU', $message);
        self::assertLessThanOrEqual($limit, strlen($message));
    }

    /**
     * Records either error surface through its public entry point, then
     * reads the actual event passed to the store.
     */
    private function recordedMessage(string $surface, string $text, string $secret): string
    {
        $written = [];
        $store = $this->createStub(Store::class);
        $store->method('events')->willReturnCallback(static function (array $events) use (&$written): void {
            $written = $events;
        });
        $recorder = new Recorder($store, hide: ['token']);
        $error = new \RuntimeException($text);
        if ($surface === 'job') {
            $recorder->job(new JobRan(new Message('job', 'default', 'Send', ['token' => $secret]), 0.01, $error));
        } else {
            $recorder->exception($error, 'request', ['token' => $secret]);
        }
        $recorder->flush('worker');
        $event = array_column($written, null, 'kind')[$surface];
        return $event['detail'][$surface === 'job' ? 'error' : 'message'];
    }

    /**
     * An empty queue with observable list reads; each test supplies only
     * the jobs that its assertion needs.
     */
    private function queue(): QueueStore
    {
        $queue = $this->createMock(QueueStore::class);
        $queue->method('counts')->willReturn(['pending' => 0, 'delayed' => 0, 'processing' => 0, 'oldest' => null]);
        return $queue;
    }

    private function dashboard(?QueueStore $queue = null): Dashboard
    {
        $container = new Container();
        if ($queue !== null) {
            $container->instance(QueueStore::class, $queue);
        }
        return new Dashboard($this->createStub(Store::class), new Config([
            'clockwerk' => ['name' => 'Test', 'interval' => 5, 'hide' => ['api_key']],
            'queue' => ['queue' => 'default'],
        ]), $container);
    }
}
