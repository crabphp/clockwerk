<?php

declare(strict_types=1);

namespace Crab\Clockwerk;

use Crab\Clockwerk\Commands\InstallClockwerk;
use Crab\Clockwerk\Commands\Status;
use Crab\Clockwerk\Http\ForgetJob;
use Crab\Clockwerk\Http\Gate;
use Crab\Clockwerk\Http\OwnPage;
use Crab\Clockwerk\Http\RecordRequests;
use Crab\Clockwerk\Http\RetryJob;
use Crab\Clockwerk\Http\ShowDashboard;
use Crab\Clockwerk\Http\ShowState;
use Crab\Contracts\Config\Config;
use Crab\Contracts\Console\Console;
use Crab\Contracts\Container\Container;
use Crab\Contracts\Database\Database;
use Crab\Contracts\Database\Migrators;
use Crab\Contracts\Events\Dispatcher;
use Crab\Contracts\Http\MiddlewareStack;
use Crab\Contracts\Queue\JobRan;
use Crab\Contracts\Queue\Store as QueueStore;
use Crab\Contracts\Routing\Router;
use Crab\Contracts\Schedule\TaskRan;
use Crab\Contracts\WorkerPlugin;
use Crab\Engine\Process\Worker;

/**
 * Adds the dashboard: the page at "clockwerk.path", the recording behind it,
 * and the `clockwerk:install` and `clockwerk:status` commands. Register it after
 * the database, events, queue and schedule plugins, and before the
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
 * Jobs and scheduled tasks are recorded when EventsPlugin is
 * registered; requests always are.
 */
final class ClockwerkPlugin implements WorkerPlugin
{
    public function register(Container $container): void
    {
        $config = $container->get(Config::class);
        $config->defaults(
            'clockwerk',
            [
                'path' => '/clockwerk',
                'token' => null,
                'middleware' => [],
                'name' => null,
                'table' => 'clockwerk',
                'database' => null,
                'interval' => 5.0,
                'keep' => 24,
                'hide' => ['password', 'passwd', 'secret', 'token', 'api_key', 'apikey', 'authorization', 'cookie'],
            ],
            [
                'path' => ['type' => 'string', 'pattern' => '^/', 'examples' => ['/clockwerk']],
                'token' => ['type' => ['string', 'null'], 'minLength' => 1],
                // Middleware around the page, by class name or as objects.
                'middleware' => ['type' => 'array'],
                'name' => ['type' => ['string', 'null']],
                'table' => ['type' => 'string', 'pattern' => '^[A-Za-z_][A-Za-z0-9_]*\z', 'examples' => ['clockwerk']],
                'database' => ['type' => ['string', 'null'], 'minLength' => 1],
                'interval' => ['type' => 'number', 'minimum' => 1],
                'keep' => ['type' => 'integer', 'minimum' => 1],
                'hide' => ['type' => 'array', 'items' => ['type' => 'string', 'minLength' => 1]],
            ],
        );
        $container->singleton(Store::class, static function (Container $container): Store {
            if (!$container->has(Database::class)) {
                throw new \LogicException(
                    'The dashboard keeps what it records in the database: register the database plugin, DatabasePlugin, before ClockwerkPlugin.',
                );
            }
            $config = $container->get(Config::class);
            $config->check('clockwerk');
            $database = $container->get(Database::class);
            $name = $config->get('clockwerk.database');
            return new DatabaseStore(
                is_string($name) ? $database->connection($name) : $database,
                (string) $config->get('clockwerk.table'),
            );
        });
        $container->singleton(Recorder::class, static function (Container $container): Recorder {
            $config = $container->get(Config::class);
            $config->check('clockwerk');
            return new Recorder(
                $container->get(Store::class),
                (int) $config->get('clockwerk.keep'),
                (array) $config->get('clockwerk.hide'),
            );
        });
        $container->singleton(Dashboard::class, static function (Container $container): Dashboard {
            $config = $container->get(Config::class);
            $config->check('clockwerk');
            return new Dashboard($container->get(Store::class), $config, $container);
        });

        $container->singleton(Recording::class, Recording::class);

        // Inside the error handler, so that what a request throws is seen before it becomes a 500.
        $container->get(MiddlewareStack::class)->add(RecordRequests::class);
        if ($container->has(Dispatcher::class)) {
            $events = $container->get(Dispatcher::class);
            $events->listen(JobRan::class, static fn(JobRan $event) => $container->get(Recorder::class)->job($event));
            $events->listen(TaskRan::class, static fn(TaskRan $event) => $container->get(Recorder::class)->task(
                $event,
            ));
        }

        $guards = array_values((array) $config->get('clockwerk.middleware'));
        $queue = $container->has(QueueStore::class);
        $path = rtrim((string) $config->get('clockwerk.path'), '/');
        // The page holds nothing of the application: it is a shell that asks for what to show, and
        // says what is missing when it is refused. With the token it is open, so that it can be
        // loaded again without the token in the address; middleware of the application's own guards it too.
        $router = $container->get(Router::class);
        $router->get($path === '' ? '/' : $path, ShowDashboard::class)->middleware(...$guards);
        $api = $router->middleware(...$guards === [] ? [Gate::class] : $guards);
        if ($path !== '') {
            $api = $api->prefix($path);
        }
        $api->group(static function (Router $router) use ($queue): void {
            $router->get('/api', ShowState::class);
            if ($queue) {
                $router->post('/api/jobs/retry', RetryJob::class)->middleware(OwnPage::class);
                $router->post('/api/jobs/forget', ForgetJob::class)->middleware(OwnPage::class);
            }
        });

        if ($container->has(Console::class)) {
            $console = $container->get(Console::class);
            $console->add('clockwerk:status', Status::class);
            if ($container->has(Migrators::class)) {
                $console->add('clockwerk:install', InstallClockwerk::class);
            }
        }
    }

    public function start(Worker $worker, Container $container): void
    {
        $container->get(Recording::class)->start($worker, $container);
    }

    public function stop(Worker $worker, Container $container): void
    {
        $container->get(Recording::class)->stop($worker, $container);
    }
}
