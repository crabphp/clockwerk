<?php

declare(strict_types=1);

namespace Crab\Clockwerk;

use Crab\Contracts\Config\Config;
use Crab\Contracts\Queue\Store as QueueStore;
use Crab\Contracts\Schedule\Schedule;
use Psr\Container\ContainerInterface;

/**
 * Everything the dashboard shows, as one array: what the workers
 * recorded, added up, beside what the queue and the schedule say of
 * themselves now.
 */
final readonly class Dashboard
{
    /**
     * How many failed jobs the page is given at most.
     */
    private const FAILED_SHOWN = 200;

    public function __construct(private Store $store, private Config $config, private ContainerInterface $container)
    {
    }

    /**
     * @param int $minutes How far back the counts go.
     * @return array<string, mixed>
     */
    public function state(int $minutes = 60): array
    {
        $minutes = max(5, min($minutes, 24 * 60));
        $now = time();
        $since = intdiv($now, 60) * 60 - ($minutes - 1) * 60;
        $interval = (float) $this->config->get('clockwerk.interval');
        return [
            'app' => (string) ($this->config->get('clockwerk.name') ?? 'Crab'),
            'now' => $now,
            'minutes' => $minutes,
            'since' => $since,
            'requests' => $this->measured('request', $since),
            'jobs' => $this->measured('job', $since) + $this->queue($since, $now),
            'tasks' => $this->tasks($since),
            // A worker is alive while it was heard from within three of its writes.
            'workers' => $this->store->workers($now - (int) ceil($interval * 3) - 2),
            'exceptions' => $this->store->recent('exception', $since, 200),
        ];
    }

    /**
     * @return array{total: int, errors: int, avg_ms: float, max_ms: float, series: list<array<string, int|float>>, rows: list<array<string, mixed>>}
     */
    private function measured(string $kind, int $since): array
    {
        // The whole of it, from the counts by minute: the list by name below is cut to the busiest.
        $series = $this->store->series($kind, $since);
        $total = $errors = 0;
        $time = $max = 0.0;
        foreach ($series as $point) {
            $total += $point['runs'];
            $errors += $point['errors'];
            $time += $point['total_ms'];
            $max = max($max, $point['max_ms']);
        }
        $rows = [];
        foreach ($this->store->totals($kind, $since) as $row) {
            $rows[] = [
                'name' => $row['name'], 'runs' => $row['runs'], 'errors' => $row['errors'],
                'avg_ms' => $row['runs'] > 0 ? round($row['total_ms'] / $row['runs'], 2) : 0.0,
                'max_ms' => round($row['max_ms'], 2), 'last' => $row['last'],
            ];
        }
        return [
            'total' => $total, 'errors' => $errors,
            'avg_ms' => $total > 0 ? round($time / $total, 2) : 0.0, 'max_ms' => round($max, 2),
            'series' => $series,
            'rows' => $rows,
        ];
    }

    /**
     * The queue as it is now: its supervisors, how many jobs wait in each
     * queue, and the jobs given up on.
     *
     * @return array<string, mixed>
     */
    private function queue(int $since, int $now): array
    {
        if (!$this->container->has(QueueStore::class)) {
            return ['installed' => false, 'supervisors' => [], 'queues' => [], 'recent' => [], 'failed' => [], 'failed_total' => 0, 'failed_more' => false];
        }
        $store = $this->container->get(QueueStore::class);
        $names = [(string) $this->config->get('queue.queue', 'default') => true];
        $supervisors = [];
        foreach ((array) $this->config->get('queue.supervisors', []) as $name => $settings) {
            // One that names no queue takes from the queue's own, as the framework has it.
            $queues = array_values(array_map(strval(...), (array) ($settings['queues'] ?? [(string) $this->config->get('queue.queue', 'default')])));
            foreach ($queues as $queue) {
                $names[$queue] = true;
            }
            $supervisors[] = [
                'name' => (string) $name, 'queues' => $queues,
                'workers' => (int) ($settings['workers'] ?? $this->config->get('queue.workers', 1)),
                'concurrency' => (int) ($settings['concurrency'] ?? $this->config->get('queue.concurrency', 1)),
                'tries' => (int) ($settings['tries'] ?? $this->config->get('queue.tries', 3)),
            ];
        }
        // The latest, and one more to know that there are more: a backlog of thousands is not read on every look.
        $failed = $store->failed(self::FAILED_SHOWN + 1);
        $more = count($failed) > self::FAILED_SHOWN;
        $failed = array_slice($failed, -self::FAILED_SHOWN);
        foreach ($failed as $message) {
            $names[$message->queue] = true;
        }
        // What the workers did in each queue in the time asked for, beside what is in it now.
        // Every queue, not only the busiest: an application has few of them, and each is shown.
        $tried = array_column($this->store->totals('queue', $since, 5000), null, 'name');
        $gaveUp = array_column($this->store->totals('gaveup', $since, 5000), 'runs', 'name');
        foreach (array_keys($tried) as $queue) {
            $names[$queue] = true;
        }
        $queues = [];
        foreach (array_keys($names) as $queue) {
            $queue = (string) $queue;
            $counts = $store->counts($queue, $now);
            $tries = $tried[$queue]['runs'] ?? 0;
            $errors = $tried[$queue]['errors'] ?? 0;
            $queues[] = [
                'name' => $queue, 'size' => $counts['pending'] + $counts['delayed'] + $counts['processing'],
                'pending' => $counts['pending'], 'delayed' => $counts['delayed'], 'processing' => $counts['processing'],
                // How long the pending job that has waited longest has been waiting: how far behind the workers are.
                'longest_wait' => $counts['oldest'] === null ? null : max(0, $now - $counts['oldest']),
                'completed' => $tries - $errors, 'failed' => $gaveUp[$queue] ?? 0, 'retried' => max(0, $errors - ($gaveUp[$queue] ?? 0)),
                'avg_ms' => $tries > 0 ? round($tried[$queue]['total_ms'] / $tries, 2) : 0.0,
            ];
        }
        $recent = [];
        foreach ($this->store->recent('job', $since, 100) as $event) {
            $recent[] = ['at' => $event['at'], 'job' => $event['title'], ...$event['detail']];
        }
        $shown = [];
        // The newest first, and no more than a page can show.
        foreach (array_reverse($failed) as $message) {
            $shown[] = [
                'id' => (string) $message->id, 'job' => $message->job, 'queue' => $message->queue, 'attempts' => $message->attempts,
                'error' => $message->error === null ? null : mb_strcut($message->error, 0, 2000), 'failed_at' => $message->failedAt,
                'data' => $this->hidden($message->data),
            ];
        }
        return ['installed' => true, 'supervisors' => $supervisors, 'queues' => $queues, 'recent' => $recent, 'failed' => $shown, 'failed_total' => count($shown), 'failed_more' => $more];
    }

    /**
     * A job's data with the values of the fields named in "clockwerk.hide"
     * left out, at any depth: a password or a reset token pushed with a
     * job is not for everyone who may open the dashboard.
     *
     * @param array<int|string, mixed> $data
     * @return array<int|string, mixed>
     */
    private function hidden(array $data): array
    {
        $hide = array_map(strtolower(...), array_map(strval(...), (array) $this->config->get('clockwerk.hide')));
        $walk = static function (array $values) use (&$walk, $hide): array {
            foreach ($values as $name => $value) {
                $lower = strtolower((string) $name);
                $secret = false;
                foreach ($hide as $word) {
                    $secret = $secret || ($word !== '' && str_contains($lower, $word));
                }
                $values[$name] = $secret ? '(hidden)' : (is_array($value) ? $walk($value) : $value);
            }
            return $values;
        };
        return $walk($data);
    }

    /**
     * The scheduled tasks as they are declared, each with how it has run.
     *
     * @return array{installed: bool, rows: list<array<string, mixed>>}
     */
    private function tasks(int $since): array
    {
        if (!$this->container->has(Schedule::class)) {
            return ['installed' => false, 'rows' => []];
        }
        $ran = [];
        foreach ($this->store->totals('task', $since, 2000) as $row) {
            $ran[$row['name']] = $row;
        }
        $zone = $this->config->get('schedule.timezone');
        $now = new \DateTimeImmutable('now', is_string($zone) && $zone !== '' ? new \DateTimeZone($zone) : null);
        $rows = [];
        foreach ($this->container->get(Schedule::class)->tasks() as $task) {
            $name = $task->named();
            $row = $ran[$name] ?? null;
            $rows[] = [
                'name' => $name, 'when' => $task->expression(), 'one_server' => $task->runsOnOneServer(),
                'next' => $task->interval() === null ? $task->next($now)->getTimestamp() : null,
                'runs' => $row['runs'] ?? 0, 'errors' => $row['errors'] ?? 0,
                'avg_ms' => $row !== null && $row['runs'] > 0 ? round($row['total_ms'] / $row['runs'], 2) : 0.0,
                'max_ms' => round($row['max_ms'] ?? 0.0, 2), 'last' => $row['last'] ?? null,
            ];
        }
        return ['installed' => true, 'rows' => $rows];
    }
}
