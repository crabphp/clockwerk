<?php

declare(strict_types=1);

namespace Crab\Clockwerk;

/**
 * Where the dashboard's numbers are kept, shared by every worker of
 * every server. Times are Unix timestamps; a bucket is the timestamp of
 * the start of a minute.
 */
interface Store
{
    /**
     * Writes a worker's own counts for some minutes, in place of what it
     * wrote for them before. Each worker counts what it did itself, and
     * the dashboard adds the workers up.
     *
     * @param list<array{kind: string, name: string, bucket: int, runs: int, total_ms: float, max_ms: float, errors: int}> $metrics
     */
    public function metrics(string $worker, array $metrics): void;

    /**
     * Keeps things worth reading one by one, such as exceptions.
     *
     * @param list<array{kind: string, at: int, title: string, detail: array<string, mixed>}> $events
     */
    public function events(array $events): void;

    /**
     * Says that a worker is alive, and how it is.
     *
     * @param array{id: string, group_name: string, pid: int, host: string, started_at: int, seen_at: int, memory: int, handled: int} $worker
     */
    public function beat(array $worker): void;

    /**
     * Forgets a worker that has stopped.
     */
    public function gone(string $worker): void;

    /**
     * The counts of one kind since a time, added up by name, the busiest
     * first, and no more names than the limit.
     *
     * @return list<array{name: string, runs: int, total_ms: float, max_ms: float, errors: int, last: int}>
     */
    public function totals(string $kind, int $since, int $limit = 300): array;

    /**
     * The counts of one kind since a time, added up by minute, the earliest first.
     *
     * @return list<array{bucket: int, runs: int, total_ms: float, max_ms: float, errors: int}>
     */
    public function series(string $kind, int $since): array;

    /**
     * @return list<array{at: int, title: string, detail: array<string, mixed>}> The latest events of one kind since a time, the newest first.
     */
    public function recent(string $kind, int $since, int $limit): array;

    /**
     * @return list<array{id: string, group_name: string, pid: int, host: string, started_at: int, seen_at: int, memory: int, handled: int}> The workers heard from since a time.
     */
    public function workers(int $seenSince): array;

    /**
     * Deletes what is older than a time.
     */
    public function prune(int $before): void;
}
