<?php

declare(strict_types=1);

namespace Crab\Clockwerk\Tests;

use Crab\App;
use Crab\Cache\CacheProvider;
use Crab\Config\Config;
use Crab\Console\Output;
use Crab\Contracts\Queue\Queue;
use Crab\Contracts\Schedule\Schedule;
use Crab\Database\DatabaseProvider;
use Crab\Events\EventsProvider;
use Crab\Http\HttpException;
use Crab\Http\Request;
use Crab\Queue\QueueProvider;
use Crab\Routing\Router;
use Crab\Schedule\ScheduleProvider;
use Crab\Schedule\Scheduler;
use Crab\Clockwerk\Dashboard;
use Crab\Clockwerk\Recorder;
use Crab\Clockwerk\RouteNames;
use Crab\Clockwerk\Secrets;
use Crab\Clockwerk\Store;
use Crab\Clockwerk\ClockwerkProvider;
use Crab\Http\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class Greet
{
    public function handle(string $name, array $options = []): void
    {
        if ($name === 'nobody') {
            throw new \RuntimeException('There is nobody to greet');
        }
    }
}

final class Leaky
{
    public function handle(string $reset_token): void
    {
        throw new \RuntimeException("GET https://mail.example.com/reset/{$reset_token} answered 500");
    }
}

final class OnlyAdmins implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return str_contains($request->getHeaderLine('Cookie'), 'admin=1') ? $handler->handle($request) : Response::text('Log in first.', 401);
    }
}

final class ClockwerkTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/crab-clockwerk-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/migrations', recursive: true);
    }

    protected function tearDown(): void
    {
        foreach ([...(glob($this->directory . '/migrations/*') ?: []), ...(glob($this->directory . '/*.*') ?: [])] as $file) {
            unlink($file);
        }
        rmdir($this->directory . '/migrations');
        rmdir($this->directory);
    }

    public function testARequestIsNamedAfterTheRouteItMatched(): void
    {
        $router = (new Router())
            ->get('/', static fn () => '')
            ->get('/users/{id}', static fn (int $id) => '')
            ->get('/files/{path:.+}', static fn (string $path) => '')
            ->post('/users/{id}/posts/{slug:[a-z-]+}', static fn (int $id, string $slug) => '');
        $names = new RouteNames($router);

        self::assertSame('/', $names->of('GET', '/'));
        self::assertSame('/users/{id}', $names->of('GET', '/users/42'));
        self::assertSame('/users/{id}', $names->of('HEAD', '/users/42'));
        self::assertSame('/files/{path:.+}', $names->of('GET', '/files/a/b.txt'));
        self::assertSame('/users/{id}/posts/{slug:[a-z-]+}', $names->of('POST', '/users/7/posts/hello-world'));
        self::assertNull($names->of('POST', '/users/7/posts/NOPE'));
        self::assertNull($names->of('GET', '/users/42/more'));
        self::assertNull($names->of('DELETE', '/users/42'));

        // A HEAD route of its own, and an expression with the character this matcher uses as its delimiter.
        $more = new RouteNames((new Router())->add('HEAD', '/head/{id}', static fn (int $id) => '')->get('/tilde/{id:[a-z~]+}', static fn (string $id) => ''));
        self::assertSame('/head/{id}', $more->of('HEAD', '/head/5'));
        self::assertSame('/tilde/{id:[a-z~]+}', $more->of('GET', '/tilde/a~b'));
        self::assertSame('/tilde/{id:[a-z~]+}', $more->of('HEAD', '/tilde/a~b'));
    }

    public function testWhatTheDashboardShowsIsClosedUntilItHasATokenAndOpenToWhoeverKnowsIt(): void
    {
        $closed = $this->app([]);
        $response = $closed->handle(new Request('GET', '/clockwerk/api'));
        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('clockwerk.token', (string) $response->getBody());

        $app = $this->app(['token' => 's3cret']);
        self::assertSame(401, $app->handle(new Request('GET', '/clockwerk/api'))->getStatusCode());
        self::assertSame(401, $app->handle(new Request('GET', '/clockwerk/api?token=wrong'))->getStatusCode());
        self::assertSame(401, $app->handle(new Request('POST', '/clockwerk/api/jobs/forget', ['Content-Type' => 'application/json'], '{"id":"all"}'))->getStatusCode());
        self::assertSame(200, $app->handle(new Request('GET', '/clockwerk/api?token=s3cret'))->getStatusCode());
        self::assertSame(200, $app->handle(new Request('GET', '/clockwerk/api', ['Authorization' => 'Bearer s3cret']))->getStatusCode());

        // The page is a shell with nothing of the application in it, so it loads again
        // after the token has been taken out of the address, and asks for the token itself.
        foreach ([$closed, $app] as $either) {
            $page = $either->handle(new Request('GET', '/clockwerk'));
            self::assertSame(200, $page->getStatusCode());
            self::assertStringContainsString('text/html', $page->getHeaderLine('Content-Type'));
            self::assertStringContainsString('<title>Clockwerk</title>', (string) $page->getBody());
            // Nothing is fetched from anywhere else: the page is whole in one file.
            self::assertDoesNotMatchRegularExpression('~(src|href)="https?://~', (string) $page->getBody());
        }
    }

    public function testMiddlewareOfTheApplicationsOwnGuardsThePageTooAndAnotherSiteCannotPostToIt(): void
    {
        $app = $this->app(['middleware' => [OnlyAdmins::class]]);

        self::assertSame(401, $app->handle(new Request('GET', '/clockwerk'))->getStatusCode());
        self::assertSame(401, $app->handle(new Request('GET', '/clockwerk/api'))->getStatusCode());
        $admin = ['Cookie' => 'admin=1'];
        self::assertSame(200, $app->handle(new Request('GET', '/clockwerk', $admin))->getStatusCode());
        self::assertSame(200, $app->handle(new Request('GET', '/clockwerk/api', $admin))->getStatusCode());

        // A form on another site, sent with the visitor's cookie, is refused; so is JSON from elsewhere.
        $post = static fn (array $headers, string $body): int => $app->handle(new Request('POST', '/clockwerk/api/jobs/forget', $admin + ['Host' => 'app.example.com'] + $headers, $body))->getStatusCode();
        self::assertSame(403, $post(['Content-Type' => 'application/x-www-form-urlencoded', 'Origin' => 'https://evil.example'], 'id=all'));
        self::assertSame(403, $post(['Content-Type' => 'application/json', 'Origin' => 'https://evil.example'], '{"id":"all"}'));
        self::assertSame(403, $post(['Content-Type' => 'application/json', 'Sec-Fetch-Site' => 'cross-site'], '{"id":"all"}'));
        self::assertSame(403, $post(['Content-Type' => 'text/plain'], '{"id":"all"}'));
        // The page's own request.
        self::assertSame(200, $post(['Content-Type' => 'application/json', 'Origin' => 'https://app.example.com', 'Sec-Fetch-Site' => 'same-origin'], '{"id":"all"}'));
    }

    public function testRequestsJobsAndTasksAreCountedAndShown(): void
    {
        $app = $this->app(['token' => 's3cret', 'name' => 'Shop']);
        $app->router->get('/users/{id}', static fn (int $id): array => ['id' => $id]);
        $app->router->get('/missing/{id}', static fn (int $id) => throw new HttpException(404, 'No such thing.'));
        $app->router->get('/broken', static fn () => throw new \LogicException('The cart has no address'));
        $schedule = $app->container->get(Schedule::class);
        $schedule->call(static fn () => null)->daily('07:00')->name('nightly');
        $schedule->call(static fn () => throw new \RuntimeException('disk full'))->everySeconds(5)->name('backup')->onOneServer();

        foreach (['/users/1', '/users/2', '/users/3', '/missing/9', '/broken', '/nowhere', '/clockwerk/api?token=s3cret'] as $path) {
            $app->handle(new Request('GET', $path));
        }
        $queue = $app->container->get(Queue::class);
        $queue->push(Greet::class, name: 'Ada');
        $queue->on('mail')->push(Greet::class, name: 'nobody', options: ['reset_token' => 'abc123', 'locale' => 'en', 'smtp' => ['Password' => 'hunter2']]);
        self::assertSame(0, $app->console->run(['queue:work', '--queue=default,mail', '--stop-when-empty'], $this->quiet()));
        $scheduler = $app->container->get(Scheduler::class);
        foreach ($schedule->tasks() as $task) {
            $scheduler->run($task, new \DateTimeImmutable());
        }
        // What a worker does every few seconds.
        $app->container->get(Recorder::class)->flush('test:1');
        $app->container->get(Store::class)->beat(['id' => 'test:1', 'group_name' => 'http', 'pid' => 1, 'host' => 'test', 'started_at' => time() - 90, 'seen_at' => time(), 'memory' => 8_000_000, 'handled' => 6]);

        $state = json_decode((string) $app->handle(new Request('GET', '/clockwerk/api?token=s3cret&minutes=15'))->getBody(), true);

        self::assertSame(['Shop', 15], [$state['app'], $state['minutes']]);
        // Six requests: the dashboard's own are not counted, and a 404 the action chose is not a failure.
        self::assertSame([6, 1], [$state['requests']['total'], $state['requests']['errors']]);
        $routes = array_column($state['requests']['rows'], null, 'name');
        self::assertSame([3, 0], [$routes['GET /users/{id}']['runs'], $routes['GET /users/{id}']['errors']]);
        self::assertSame([1, 0], [$routes['GET /missing/{id}']['runs'], $routes['GET /missing/{id}']['errors']]);
        self::assertSame([1, 1], [$routes['GET /broken']['runs'], $routes['GET /broken']['errors']]);
        self::assertSame(1, $routes['GET (no route)']['runs']);
        self::assertSame(6, array_sum(array_column($state['requests']['series'], 'runs')));

        // One job done; the other tried once, failed and, with one try allowed, kept as failed.
        self::assertSame([2, 1, 1, true], [$state['jobs']['total'], $state['jobs']['errors'], $state['jobs']['failed_total'], $state['jobs']['installed']]);
        self::assertSame(Greet::class, $state['jobs']['rows'][0]['name']);
        self::assertSame(['mail', 'There is nobody to greet', 'nobody'], [$state['jobs']['failed'][0]['queue'], substr($state['jobs']['failed'][0]['error'], -24), $state['jobs']['failed'][0]['data']['name']]);
        // What is named like a secret is not sent to the page, at any depth.
        self::assertSame(['reset_token' => '(hidden)', 'locale' => 'en', 'smtp' => ['Password' => '(hidden)']], $state['jobs']['failed'][0]['data']['options']);
        self::assertStringNotContainsString('hunter2', json_encode($state));
        self::assertFalse($state['jobs']['failed_more']);
        self::assertSame(['mail' => ['mail'], 'rest' => ['default']], array_column($state['jobs']['supervisors'], 'queues', 'name'));
        self::assertEqualsCanonicalizing(['default', 'mail'], array_column($state['jobs']['queues'], 'name'));
        // Each queue by what stands in it now and what its workers did: one job completed in "default", one given up on in "mail".
        $queues = array_column($state['jobs']['queues'], null, 'name');
        self::assertSame([0, 0, 0, 1, 0, 0, null], [$queues['default']['pending'], $queues['default']['delayed'], $queues['default']['processing'], $queues['default']['completed'], $queues['default']['retried'], $queues['default']['failed'], $queues['default']['longest_wait']]);
        self::assertSame([0, 0, 1], [$queues['mail']['completed'], $queues['mail']['retried'], $queues['mail']['failed']]);
        // And the latest tries one by one, with how each ended.
        $recent = array_column($state['jobs']['recent'], null, 'outcome');
        self::assertEqualsCanonicalizing(['completed', 'failed'], array_keys($recent));
        self::assertSame([Greet::class, 'mail', 1], [$recent['failed']['job'], $recent['failed']['queue'], $recent['failed']['attempts']]);
        self::assertStringContainsString('There is nobody to greet', $recent['failed']['error']);
        self::assertNull($recent['completed']['error']);

        // Jobs pushed and not yet run: pending now, or delayed, and the oldest has waited since it was pushed.
        $queue->push(Greet::class, name: 'waiting');
        $queue->later(600, Greet::class, name: 'later');
        $waiting = array_column(json_decode((string) $app->handle(new Request('GET', '/clockwerk/api?token=s3cret&minutes=15'))->getBody(), true)['jobs']['queues'], null, 'name')['default'];
        self::assertSame([1, 1, 0, 2], [$waiting['pending'], $waiting['delayed'], $waiting['processing'], $waiting['size']]);
        self::assertLessThan(5, $waiting['longest_wait']);
        // Asked for, the jobs standing in a state are listed one by one, with their data and nothing named like a secret.
        $queue->later(900, Greet::class, name: 'much later', options: ['api_key' => 'k-123']);
        $listed = static fn (string $state): array => json_decode((string) $app->handle(new Request('GET', "/clockwerk/api?token=s3cret&list={$state}"))->getBody(), true)['jobs'];
        self::assertSame(['waiting'], array_map(static fn (array $job): string => $job['data']['name'], $listed('pending')['list']));
        $delayed = $listed('delayed');
        self::assertSame(['delayed', ['later', 'much later']], [$delayed['list_state'], array_map(static fn (array $job): string => $job['data']['name'], $delayed['list'])]);
        self::assertSame(['api_key' => '(hidden)'], $delayed['list'][1]['data']['options']);
        self::assertGreaterThan(time(), $delayed['list'][0]['available_at']);
        self::assertSame([], $listed('processing')['list']);
        // Not asked for, they are not read; and a state that is none is refused.
        self::assertSame([], json_decode((string) $app->handle(new Request('GET', '/clockwerk/api?token=s3cret'))->getBody(), true)['jobs']['list']);
        self::assertSame(422, $app->handle(new Request('GET', '/clockwerk/api?token=s3cret&list=done'))->getStatusCode());
        self::assertSame(0, $app->console->run(['queue:work', '--queue=default', '--stop-when-empty'], $this->quiet()));

        $tasks = array_column($state['tasks']['rows'], null, 'name');
        self::assertSame(['0 7 * * *', 1, 0, false], [$tasks['nightly']['when'], $tasks['nightly']['runs'], $tasks['nightly']['errors'], $tasks['nightly']['one_server']]);
        self::assertSame(['every 5 s', 1, 1, true, null], [$tasks['backup']['when'], $tasks['backup']['runs'], $tasks['backup']['errors'], $tasks['backup']['one_server'], $tasks['backup']['next']]);
        self::assertGreaterThan(time(), $tasks['nightly']['next']);

        self::assertSame([['http', 6]], array_map(static fn (array $worker): array => [$worker['group_name'], $worker['handled']], $state['workers']));
        $during = array_column(array_column($state['exceptions'], 'detail'), 'during', 'message');
        self::assertEquals(['The cart has no address' => 'GET /broken', 'There is nobody to greet' => 'job ' . Greet::class . ', try 1, the last', 'disk full' => 'task backup'], $during);

        // A failed job is put back from the dashboard, and then forgotten.
        $post = static fn (string $path, string $id) => json_decode((string) $app->handle(new Request('POST', $path, ['Authorization' => 'Bearer s3cret', 'Content-Type' => 'application/json'], json_encode(['id' => $id])))->getBody(), true);
        self::assertSame(['retried' => 1], $post('/clockwerk/api/jobs/retry', $state['jobs']['failed'][0]['id']));
        self::assertSame(0, $app->console->run(['queue:work', '--queue=mail', '--stop-when-empty'], $this->quiet()));
        self::assertSame(['forgotten' => 1], $post('/clockwerk/api/jobs/forget', 'all'));
        self::assertSame(['forgotten' => 0], $post('/clockwerk/api/jobs/forget', 'all'));

        [$output, $read] = $this->captured();
        self::assertSame(0, $app->console->run(['clockwerk:status', '--minutes=15'], $output));
        self::assertStringContainsString('1 http', $read());
        self::assertMatchesRegularExpression('/Requests\s+6, 1 failed/', $read());
    }

    public function testTheTotalsCountEveryNameAndNamesThatDifferOnlyInCaseStayApart(): void
    {
        $app = $this->app(['token' => 's3cret']);
        $store = $app->container->get(Store::class);
        $bucket = intdiv(time(), 60) * 60;
        $rows = [];
        for ($i = 0; $i < 320; ++$i) {
            $rows[] = ['kind' => 'request', 'name' => "GET /r{$i}", 'bucket' => $bucket, 'runs' => 2, 'total_ms' => 4.0, 'max_ms' => 3.0, 'errors' => 0];
        }
        // The least busy of all, and the only one that failed.
        $rows[] = ['kind' => 'request', 'name' => 'GET /broken', 'bucket' => $bucket, 'runs' => 1, 'total_ms' => 50.0, 'max_ms' => 50.0, 'errors' => 1];
        $rows[] = ['kind' => 'request', 'name' => 'GET /Foo', 'bucket' => $bucket, 'runs' => 10, 'total_ms' => 10.0, 'max_ms' => 1.0, 'errors' => 0];
        $rows[] = ['kind' => 'request', 'name' => 'GET /foo', 'bucket' => $bucket, 'runs' => 7, 'total_ms' => 7.0, 'max_ms' => 1.0, 'errors' => 0];
        foreach (array_chunk($rows, 100) as $chunk) {
            $store->metrics('w', $chunk);
        }

        $requests = $app->container->get(Dashboard::class)->state(15)['requests'];

        // The list is cut to the busiest; what is said of the whole is not.
        self::assertCount(300, $requests['rows']);
        self::assertSame([658, 1, 50.0], [$requests['total'], $requests['errors'], $requests['max_ms']]);
        $byName = array_column($requests['rows'], 'runs', 'name');
        self::assertSame([10, 7], [$byName['GET /Foo'], $byName['GET /foo']]);
    }

    public function testADatabaseThatIsDownCostsTheCountsAndNotTheWorkersMemory(): void
    {
        $failing = new class implements Store {
            public int $asked = 0;

            public function metrics(string $worker, array $metrics): void
            {
                ++$this->asked;
                throw new \RuntimeException('The database has gone away');
            }

            public function events(array $events): void
            {
            }

            public function beat(array $worker): void
            {
            }

            public function gone(string $worker): void
            {
            }

            public function totals(string $kind, int $since, int $limit = 300): array
            {
                return [];
            }

            public function series(string $kind, int $since): array
            {
                return [];
            }

            public function recent(string $kind, int $since, int $limit): array
            {
                return [];
            }

            public function workers(int $seenSince): array
            {
                return [];
            }

            public function prune(int $before): void
            {
            }
        };
        $recorder = new Recorder($failing);
        $held = new \ReflectionProperty($recorder, 'metrics');
        $changed = new \ReflectionProperty($recorder, 'changed');
        $events = new \ReflectionProperty($recorder, 'events');

        for ($round = 0; $round < 50; ++$round) {
            for ($i = 0; $i < 20; ++$i) {
                $recorder->measure('request', "GET /r{$i}", 1.0, false);
                $recorder->exception(new \RuntimeException(str_repeat('x', 100_000)), 'GET /r');
            }
            try {
                $recorder->flush('w');
                self::fail('The store did not fail.');
            } catch (\RuntimeException) {
            }
        }

        // What could not be written is let go: only the current minute's counts are held.
        self::assertSame(50, $failing->asked);
        self::assertLessThanOrEqual(40, count($held->getValue($recorder)));
        self::assertSame([], $changed->getValue($recorder));
        self::assertSame([], $events->getValue($recorder));

        // And one enormous message is cut to a size a row can hold.
        $recorder->exception(new \RuntimeException(str_repeat('é', 70_000)), 'job');
        // Well under the 65,535 bytes of a text column, however it is escaped.
        self::assertLessThan(30_000, strlen((string) json_encode($events->getValue($recorder)[0]['detail'])));
    }

    public function testItsTablesCanLiveInADatabaseOtherThanTheApplicationsOwn(): void
    {
        mkdir($this->directory . '/metrics');
        $app = new App(new Config([
            'clockwerk' => ['token' => 's3cret', 'database' => 'metrics'],
            'log' => ['path' => $this->directory . '/app.log'],
            'database' => ['default' => 'main', 'connections' => [
                'main' => ['dsn' => 'sqlite:' . $this->directory . '/app.sqlite', 'migrations' => $this->directory . '/migrations'],
                'metrics' => ['dsn' => 'sqlite:' . $this->directory . '/metrics.sqlite', 'migrations' => $this->directory . '/metrics'],
            ]],
        ]));
        $app->register(DatabaseProvider::class, ClockwerkProvider::class);
        $app->router->get('/ping', static fn (): array => ['pong' => true]);

        // The migration is written among that database's, and run with them.
        self::assertSame(0, $app->console->run(['clockwerk:install'], $this->quiet()));
        self::assertCount(1, glob($this->directory . '/metrics/*_create_clockwerk_tables.php') ?: []);
        self::assertSame([], glob($this->directory . '/migrations/*') ?: []);
        self::assertSame(0, $app->console->run(['migrate', '--database=metrics'], $this->quiet()));

        $app->handle(new Request('GET', '/ping'));
        $app->container->get(Recorder::class)->flush('test:1');
        self::assertSame(1, json_decode((string) $app->handle(new Request('GET', '/clockwerk/api?token=s3cret'))->getBody(), true)['requests']['total']);

        // What was recorded is there, and the application's own database holds none of it.
        $tables = static fn (string $file): array => array_column((new \PDO('sqlite:' . $file))->query("select name from sqlite_master where type = 'table' and name like 'clockwerk%' order by name")->fetchAll(), 'name');
        self::assertSame(['clockwerk_events', 'clockwerk_metrics', 'clockwerk_workers'], $tables($this->directory . '/metrics.sqlite'));
        self::assertSame([], is_file($this->directory . '/app.sqlite') ? $tables($this->directory . '/app.sqlite') : []);
        foreach (glob($this->directory . '/metrics/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory . '/metrics');
    }

    public function testASecretIsKeptOffThePageWhereverItTurnsUp(): void
    {
        $secrets = new Secrets(['password', 'token', 'api_key']);
        $data = ['email' => 'ada@example.com', 'reset_token' => 'tok_9f8e7d6c', 'smtp' => ['Password' => 'hunter2!', 'host' => 'mail.example.com'], 'pin' => 12];

        self::assertSame(['email' => 'ada@example.com', 'reset_token' => '(hidden)', 'smtp' => ['Password' => '(hidden)', 'host' => 'mail.example.com'], 'pin' => 12], $secrets->hidden($data));
        // The value itself, quoted by an error, as it was and as an address carries it.
        self::assertSame('GET https://mail.example.com/reset/(hidden) answered 500', $secrets->scrubbed('GET https://mail.example.com/reset/tok_9f8e7d6c answered 500', $data));
        self::assertSame('login as ada@example.com with (hidden) was refused', $secrets->scrubbed('login as ada@example.com with hunter2%21 was refused', $data));
        // And what follows a secret's name, whatever data there was.
        self::assertSame('GET /pay?order=5&api_key=(hidden)&page=2 failed', $secrets->scrubbed('GET /pay?order=5&api_key=sk_live_123&page=2 failed'));
        self::assertSame('Authorization: (hidden) was rejected; X-Token: (hidden)', (new Secrets(['authorization', 'token']))->scrubbed('Authorization: Bearer abc.def.ghi was rejected; X-Token: zzz'));
        self::assertSame('Nothing secret here: order 5 of 12', $secrets->scrubbed('Nothing secret here: order 5 of 12', $data));

        // A job that fails with its own secret in the error: the page is given neither.
        $app = $this->app(['token' => 's3cret']);
        $queue = $app->container->get(Queue::class);
        $queue->push(Leaky::class, reset_token: 'tok_9f8e7d6c');
        // On a queue no supervisor serves and nothing has run on: it is seen all the same.
        $queue->on('forgotten')->push(Greet::class, name: 'nobody');
        self::assertSame(0, $app->console->run(['queue:work', '--queue=default', '--stop-when-empty'], $this->quiet()));
        $app->container->get(Recorder::class)->flush('test:1');
        $body = (string) $app->handle(new Request('GET', '/clockwerk/api?token=s3cret'))->getBody();
        self::assertStringNotContainsString('tok_9f8e7d6c', $body);
        $state = json_decode($body, true);
        self::assertStringContainsString('reset/(hidden) answered 500', $state['jobs']['failed'][0]['error']);
        self::assertStringContainsString('reset/(hidden) answered 500', $state['exceptions'][0]['title']);
        self::assertSame(1, array_column($state['jobs']['queues'], 'pending', 'name')['forgotten']);

        [$output, $read] = $this->captured();
        self::assertSame(0, $app->console->run(['clockwerk:status'], $output));
        self::assertStringContainsString('1 failed for good', $read());
    }

    public function testMountedAtTheRootItStillCountsTheApplicationsRequests(): void
    {
        $app = $this->app(['token' => 's3cret', 'path' => '/']);
        $app->router->get('/ping', static fn (): array => ['pong' => true]);

        self::assertSame(200, $app->handle(new Request('GET', '/'))->getStatusCode());
        $app->handle(new Request('GET', '/ping'));
        $app->handle(new Request('GET', '/api?token=s3cret'));
        $app->container->get(Recorder::class)->flush('test:1');

        $requests = json_decode((string) $app->handle(new Request('GET', '/api?token=s3cret'))->getBody(), true)['requests'];
        self::assertSame([1, ['GET /ping']], [$requests['total'], array_column($requests['rows'], 'name')]);
    }

    public function testWhatIsOlderThanItIsKeptForIsDeleted(): void
    {
        $app = $this->app(['token' => 's3cret']);
        $store = $app->container->get(Store::class);
        $old = time() - 3 * 3600;
        $store->metrics('w', [['kind' => 'request', 'name' => 'GET /', 'bucket' => intdiv($old, 60) * 60, 'runs' => 4, 'total_ms' => 8.0, 'max_ms' => 3.0, 'errors' => 0]]);
        $store->metrics('w', [['kind' => 'request', 'name' => 'GET /', 'bucket' => intdiv(time(), 60) * 60, 'runs' => 2, 'total_ms' => 4.0, 'max_ms' => 3.0, 'errors' => 1]]);
        // A worker rewrites its own minute: the counts are replaced, not added to.
        $store->metrics('w', [['kind' => 'request', 'name' => 'GET /', 'bucket' => intdiv(time(), 60) * 60, 'runs' => 5, 'total_ms' => 10.0, 'max_ms' => 4.0, 'errors' => 1]]);
        $store->metrics('other', [['kind' => 'request', 'name' => 'GET /', 'bucket' => intdiv(time(), 60) * 60, 'runs' => 1, 'total_ms' => 9.0, 'max_ms' => 9.0, 'errors' => 0]]);
        $store->events([['kind' => 'exception', 'at' => $old, 'title' => 'old', 'detail' => []], ['kind' => 'exception', 'at' => time(), 'title' => 'new', 'detail' => ['message' => 'x']]]);

        self::assertSame([['GET /', 10, 27.0, 9.0, 1]], array_map(static fn (array $row): array => [$row['name'], $row['runs'], $row['total_ms'], $row['max_ms'], $row['errors']], $store->totals('request', $old - 60)));
        self::assertSame(6, $app->container->get(Dashboard::class)->state(60)['requests']['total']);

        $store->prune(time() - 3600);

        self::assertSame(6, $store->totals('request', 0)[0]['runs']);
        self::assertSame(['new'], array_column($store->recent('exception', 0, 10), 'title'));
    }

    /**
     * @param array<string, mixed> $clockwerk
     */
    private function app(array $clockwerk): App
    {
        $app = new App(new Config([
            'clockwerk' => $clockwerk,
            'cache' => ['store' => 'array'],
            'queue' => ['tries' => 1, 'supervisors' => ['mail' => ['queues' => ['mail']], 'rest' => ['queues' => ['default']]]],
            'log' => ['path' => $this->directory . '/app.log'],
            'database' => ['dsn' => 'sqlite:' . $this->directory . '/app.sqlite', 'migrations' => $this->directory . '/migrations'],
        ]));
        $app->register(DatabaseProvider::class, EventsProvider::class, CacheProvider::class, QueueProvider::class, ScheduleProvider::class, ClockwerkProvider::class);
        foreach (['queue:install', 'clockwerk:install', 'clockwerk:install', 'migrate'] as $command) {
            self::assertSame(0, $app->console->run([$command], $this->quiet()));
        }
        return $app;
    }

    private function quiet(): Output
    {
        return $this->captured()[0];
    }

    /**
     * @return array{Output, \Closure(): string} An output, and what has been written to it.
     */
    private function captured(): array
    {
        $stream = fopen('php://memory', 'w+');
        return [new Output($stream, $stream), static function () use ($stream): string {
            rewind($stream);
            return (string) stream_get_contents($stream);
        }];
    }
}
