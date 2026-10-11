<?php

declare(strict_types=1);

namespace Crab\Clockwerk;

use Crab\Contracts\Queue\JobRan;
use Crab\Contracts\Schedule\TaskRan;

/**
 * Counts what one worker does, in memory, and writes the counts to the
 * store every few seconds. Nothing is written while a request or a job
 * runs: recording one costs an addition, whatever the store.
 *
 * There is one per worker. Each counts only its own work, by minute, and
 * rewrites its own rows, so workers never wait on one another.
 */
final class Recorder
{
    /**
     * This worker's counts, by kind, name and minute.
     *
     * @var array<string, array{kind: string, name: string, bucket: int, runs: int, total_ms: float, max_ms: float, errors: int}>
     */
    private array $metrics = [];
    /**
     * The keys of the counts that changed since they were last written.
     *
     * @var array<string, true>
     */
    private array $changed = [];
    /**
     * @var list<array{kind: string, at: int, title: string, detail: array<string, mixed>}>
     */
    private array $events = [];
    private int $handled = 0;
    /**
     * The latest jobs, kept one by one since the last write.
     *
     * @var list<array{kind: string, at: int, title: string, detail: array<string, mixed>}>
     */
    private array $recent = [];
    private int $flushes = 0;

    private readonly Secrets $secrets;

    /**
     * @param int $keep Hours the store keeps what was recorded.
     * @param array<mixed> $hide Words that name a secret; see Secrets.
     */
    public function __construct(private readonly Store $store, private readonly int $keep = 24, array $hide = [])
    {
        $this->secrets = new Secrets($hide);
    }

    /**
     * Counts one request, job or task.
     *
     * @param string $kind "request", "job" or "task".
     * @param string $name What ran: "GET /users/{id}", a job's class, a task's name.
     */
    public function measure(string $kind, string $name, float $milliseconds, bool $failed): void
    {
        ++$this->handled;
        $this->count($kind, $name, $milliseconds, $failed);
    }

    /**
     * Adds to a count, without it being one more thing this worker handled.
     */
    private function count(string $kind, string $name, float $milliseconds, bool $failed): void
    {
        $bucket = intdiv(time(), 60) * 60;
        $name = mb_substr($name, 0, 160);
        $key = "{$kind}\0{$name}\0{$bucket}";
        $metric = $this->metrics[$key] ?? ['kind' => $kind, 'name' => $name, 'bucket' => $bucket, 'runs' => 0, 'total_ms' => 0.0, 'max_ms' => 0.0, 'errors' => 0];
        ++$metric['runs'];
        $metric['total_ms'] += $milliseconds;
        $metric['max_ms'] = max($metric['max_ms'], $milliseconds);
        $metric['errors'] += $failed ? 1 : 0;
        $this->metrics[$key] = $metric;
        $this->changed[$key] = true;
    }

    /**
     * Keeps an exception to be read on the dashboard.
     *
     * @param string $during What was running: "GET /users/{id}", "job App\Jobs\SendEmail", "task backup".
     * @param array<int|string, mixed> $data The data of what was running, whose secrets the message may quote.
     */
    public function exception(\Throwable $error, string $during, array $data = []): void
    {
        // A worker that fails at everything must not fill the store between two writes.
        if (count($this->events) >= 100) {
            return;
        }
        $trace = [];
        foreach (array_slice($error->getTrace(), 0, 12) as $frame) {
            $trace[] = mb_strcut((isset($frame['class']) ? $frame['class'] . ($frame['type'] ?? '::') : '') . $frame['function'] . '()'
                . (isset($frame['file']) ? ' at ' . $frame['file'] . ':' . ($frame['line'] ?? 0) : ''), 0, 400);
        }
        // Cut to what a text column holds with room to spare: one huge message must not cost the others their place.
        $message = mb_strcut($this->secrets->scrubbed($error->getMessage(), $data), 0, 4000);
        $this->events[] = [
            'kind' => 'exception',
            'at' => time(),
            'title' => $error::class . ': ' . mb_strcut($message, 0, 200),
            'detail' => ['class' => $error::class, 'message' => $message, 'file' => $error->getFile(), 'line' => $error->getLine(), 'during' => mb_strcut($during, 0, 300), 'trace' => $trace],
        ];
    }

    public function job(JobRan $event): void
    {
        $milliseconds = $event->seconds * 1000;
        $this->measure('job', $event->message->job, $milliseconds, $event->error !== null);
        // By queue as well: how many tries, how many of them failed, and how many jobs were given up on.
        $this->count('queue', $event->message->queue, $milliseconds, $event->error !== null);
        if ($event->gaveUp) {
            $this->count('gaveup', $event->message->queue, $milliseconds, true);
        }
        // The latest few are kept one by one, to be read: the newest, so that under a flood what
        // is shown is what has just happened. The counts above still tell all.
        $this->recent[] = [
            'kind' => 'job',
            'at' => time(),
            'title' => $event->message->job,
            'detail' => [
                'id' => (string) $event->message->id, 'queue' => $event->message->queue, 'attempts' => $event->message->attempts,
                'ms' => round($milliseconds, 2), 'outcome' => $event->error === null ? 'completed' : ($event->gaveUp ? 'failed' : 'retrying'),
                'error' => $event->error === null ? null : mb_strcut($this->secrets->scrubbed($event->error::class . ': ' . $event->error->getMessage(), $event->message->data), 0, 500),
            ],
        ];
        if (count($this->recent) > 25) {
            array_shift($this->recent);
        }
        if ($event->error !== null) {
            $this->exception($event->error, "job {$event->message->job}, try {$event->message->attempts}" . ($event->gaveUp ? ', the last' : ''), $event->message->data);
        }
    }

    public function task(TaskRan $event): void
    {
        $this->measure('task', $event->task, $event->seconds * 1000, $event->error !== null);
        if ($event->error !== null) {
            $this->exception($event->error, "task {$event->task}");
        }
    }

    /**
     * How many requests, jobs and tasks this worker has counted since it started.
     */
    public function handled(): int
    {
        return $this->handled;
    }

    /**
     * Writes what changed since the last time, and forgets the minutes
     * that can no longer change.
     */
    public function flush(string $worker): void
    {
        $rows = [];
        foreach (array_keys($this->changed) as $key) {
            $rows[] = $this->metrics[$key];
        }
        $events = [...$this->events, ...$this->recent];
        // Cleared first: what the store refuses is lost, not sent again for ever.
        $this->changed = [];
        $this->events = [];
        $this->recent = [];
        $current = intdiv(time(), 60) * 60;
        foreach ($this->metrics as $key => $metric) {
            if ($metric['bucket'] < $current) {
                unset($this->metrics[$key]);
            }
        }
        $this->store->metrics($worker, $rows);
        $this->store->events($events);
        // Now and then, and not by every worker at once.
        if (++$this->flushes % 120 === 1 && random_int(1, 4) === 1) {
            $this->store->prune(time() - $this->keep * 3600);
        }
    }
}
