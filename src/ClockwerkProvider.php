<?php

declare(strict_types=1);

namespace Crab\Clockwerk;

use Crab\Contracts\Config\Config;
use Crab\Contracts\Console\Console;
use Crab\Contracts\Container\Container;
use Crab\Contracts\Database\Database;
use Crab\Contracts\Database\Migrator;
use Crab\Contracts\Events\Dispatcher;
use Crab\Contracts\Http\MiddlewareStack;
use Crab\Contracts\Queue\JobRan;
use Crab\Contracts\Queue\Store as QueueStore;
use Crab\Contracts\Routing\Router;
use Crab\Contracts\Schedule\TaskRan;
use Crab\Contracts\WorkerProvider;
use Crab\Engine\Process\Worker;
use Crab\Clockwerk\Commands\InstallClockwerk;
use Crab\Clockwerk\Commands\Status;
use Crab\Clockwerk\Http\ForgetJob;
use Crab\Clockwerk\Http\Gate;
use Crab\Clockwerk\Http\OwnPage;
use Crab\Clockwerk\Http\RecordRequests;
use Crab\Clockwerk\Http\RetryJob;
use Crab\Clockwerk\Http\ShowDashboard;
use Crab\Clockwerk\Http\ShowState;
use Psr\Log\LoggerInterface;

/**
 * Adds the dashboard: the page at "clockwerk.path", the recording behind it,
 * and the `clockwerk:install` and `clockwerk:status` commands. Register it after
 * the database, events, queue and schedule providers, and before the
 * application's own:
 *
 *     path         where the dashboard is; "/clockwerk"
 *     token        the secret that opens it, given as ?token=...; closed to everyone while null
 *     middleware   middleware of your own to guard it in place of the token; none
 *     name         the application's name, shown on the page; "Crab"
 *     table        what its three tables' names start with; "clockwerk"
 *     database     the name of the database they are in; the default one when null
 *     interval     seconds between two writes of a worker's counts; 5
 *     keep         hours what was recorded is kept; 24
 *     hide         a failed job's fields whose names contain one of these words are shown as "(hidden)"
 *
 * Jobs and scheduled tasks are recorded when EventsProvider is
 * registered; requests always are.
 */
final class ClockwerkProvider implements WorkerProvider
{
    /**
     * The timer that writes this worker's counts.
     */
    private ?string $timer = null;
    private string $worker = '';
    private bool $complained = false;

    public function register(Container $container): void
    {
        $config = $container->get(Config::class);
        $config->defaults('clockwerk', [
            'path' => '/clockwerk',
            'token' => null,
            'middleware' => [],
            'name' => null,
            'table' => 'clockwerk',
            'database' => null,
            'interval' => 5.0,
            'keep' => 24,
            'hide' => ['password', 'passwd', 'secret', 'token', 'api_key', 'apikey', 'authorization', 'cookie'],
        ]);
        $container->singleton(Store::class, static function (Container $container): Store {
            if (!$container->has(Database::class)) {
                throw new \LogicException('The dashboard keeps what it records in the database: register the database provider, DatabaseProvider, before ClockwerkProvider.');
            }
            $config = $container->get(Config::class);
            $database = $container->get(Database::class);
            $name = $config->get('clockwerk.database');
            return new DatabaseStore(is_string($name) ? $database->connection($name) : $database, (string) $config->get('clockwerk.table'));
        });
        $container->singleton(Recorder::class, static fn (Container $container): Recorder => new Recorder(
            $container->get(Store::class),
            max(1, (int) $container->get(Config::class)->get('clockwerk.keep')),
        ));
        $container->singleton(Dashboard::class, static fn (Container $container): Dashboard => new Dashboard(
            $container->get(Store::class),
            $container->get(Config::class),
            $container,
        ));

        // Inside the error handler, so that what a request throws is seen before it becomes a 500.
        $container->get(MiddlewareStack::class)->add(RecordRequests::class);
        if ($container->has(Dispatcher::class)) {
            $events = $container->get(Dispatcher::class);
            $events->listen(JobRan::class, static fn (JobRan $event) => $container->get(Recorder::class)->job($event));
            $events->listen(TaskRan::class, static fn (TaskRan $event) => $container->get(Recorder::class)->task($event));
        }

        $guards = array_values((array) $config->get('clockwerk.middleware'));
        $queue = $container->has(QueueStore::class);
        $path = rtrim((string) $config->get('clockwerk.path'), '/');
        // The page holds nothing of the application: it is a shell that asks for what to show, and
        // says what is missing when it is refused. With the token it is open, so that it can be
        // loaded again without the token in the address; middleware of the application's own guards it too.
        $container->get(Router::class)->get($path === '' ? '/' : $path, ShowDashboard::class, $guards);
        $container->get(Router::class)->group(static function (Router $router) use ($queue): void {
            $router->get('/api', ShowState::class);
            if ($queue) {
                $router->post('/api/jobs/retry', RetryJob::class, [OwnPage::class]);
                $router->post('/api/jobs/forget', ForgetJob::class, [OwnPage::class]);
            }
        }, prefix: $path, middleware: $guards === [] ? [Gate::class] : $guards);

        if ($container->has(Console::class)) {
            $console = $container->get(Console::class);
            $console->add('clockwerk:status', Status::class);
            if ($container->has(Migrator::class)) {
                $console->add('clockwerk:install', InstallClockwerk::class);
            }
        }
    }

    public function start(Worker $worker, Container $container): void
    {
        $started = time();
        $stats = $worker->stats();
        // With something of its own at the end: a process that takes over a host's name and a
        // process id within the minute must not write over what the one before it counted.
        $this->worker = substr((string) gethostname(), 0, 36) . ':' . $stats['pid'] . ':' . bin2hex(random_bytes(3));
        $write = function () use ($worker, $container, $started): void {
            $recorder = $container->get(Recorder::class);
            $failure = null;
            // The counts first, and whatever comes of it: the recorder lets go of what it has
            // tried to write, so a database that is down cannot make a worker's memory grow.
            try {
                $recorder->flush($this->worker);
            } catch (\Throwable $error) {
                $failure = $error;
            }
            try {
                $stats = $worker->stats();
                $container->get(Store::class)->beat([
                    'id' => $this->worker, 'group_name' => (string) $stats['group'], 'pid' => (int) $stats['pid'], 'host' => substr((string) gethostname(), 0, 120),
                    'started_at' => $started, 'seen_at' => time(), 'memory' => (int) $stats['memoryBytes'], 'handled' => $recorder->handled(),
                ]);
            } catch (\Throwable $error) {
                $failure ??= $error;
            }
            if ($failure === null) {
                $this->complained = false;
                return;
            }
            // Once, and again only after a write has worked: a dashboard that cannot write must not fill the log.
            if (!$this->complained && $container->has(LoggerInterface::class)) {
                $container->get(LoggerInterface::class)->warning('The dashboard could not write what it recorded: {message} Have `clockwerk:install` and `migrate` been run?', ['message' => $failure->getMessage(), 'exception' => $failure]);
            }
            $this->complained = true;
        };
        // As a task of the worker: the database may make its caller wait for a connection.
        $this->timer = $worker->loop->repeat(max(1.0, (float) $container->get(Config::class)->get('clockwerk.interval')), static function () use ($worker, $write): void {
            $worker->spawn($write);
        });
        $worker->spawn($write);
    }

    public function stop(Worker $worker, Container $container): void
    {
        if ($this->timer !== null) {
            $worker->loop->cancel($this->timer);
            $this->timer = null;
        }
        try {
            $container->get(Recorder::class)->flush($this->worker);
            $container->get(Store::class)->gone($this->worker);
        } catch (\Throwable) {
            // What could not be written in the worker's last moment is let go.
        }
    }
}
