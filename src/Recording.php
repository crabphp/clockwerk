<?php

declare(strict_types=1);

namespace Crab\Clockwerk;

use Crab\Contracts\Config\Config;
use Crab\Contracts\Container\Container;
use Crab\Engine\Async\Task;
use Crab\Engine\Process\Worker;
use Psr\Log\LoggerInterface;

/**
 * The timer and pending write of one worker, kept in its container.
 */
final class Recording
{
    /**
     * The timer that writes this worker's counts.
     */
    private ?string $timer = null;
    private string $worker = '';
    private bool $complained = false;
    private ?Task $writing = null;

    public function start(Worker $worker, Container $container): void
    {
        $this->complained = false;
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
                    'id' => $this->worker,
                    'group_name' => (string) $stats['group'],
                    'pid' => (int) $stats['pid'],
                    'host' => substr((string) gethostname(), 0, 120),
                    'started_at' => $started,
                    'seen_at' => time(),
                    'memory' => (int) $stats['memoryBytes'],
                    'handled' => $recorder->handled(),
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
                $container->get(
                    LoggerInterface::class,
                )->warning('The dashboard could not write what it recorded: {message} Have `clockwerk:install` and `migrate` been run?', [
                    'message' => $failure->getMessage(),
                    'exception' => $failure,
                ]);
            }
            $this->complained = true;
        };
        // As a task of the worker: the database may make its caller wait for a connection.
        $tick = function () use ($worker, $write): void {
            // One whole write at a time, including its heartbeat, even if the database waits.
            if ($worker->isDraining() || $this->writing !== null && !$this->writing->isComplete()) {
                return;
            }
            $this->writing = $worker->spawn($write);
        };
        $this->timer = $worker->loop->repeat((float) $container->get(Config::class)->get('clockwerk.interval'), $tick);
        $tick();
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
