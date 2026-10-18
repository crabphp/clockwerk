<?php

declare(strict_types=1);

namespace Crab\Clockwerk;

use Crab\Contracts\Database\Connection;

/**
 * Keeps the dashboard's numbers in three tables of the application's
 * database, which `clockwerk:install` writes the migration for.
 */
final readonly class DatabaseStore implements Store
{
    private string $metrics;
    private string $events;
    private string $workers;

    /**
     * @param string $prefix What the three tables' names start with.
     */
    public function __construct(
        private Connection $connection,
        string $prefix = 'clockwerk',
    ) {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $prefix) !== 1) {
            throw new \InvalidArgumentException(
                "\"{$prefix}\" cannot start a table's name: use letters, digits and underscores.",
            );
        }
        $this->metrics = $prefix . '_metrics';
        $this->events = $prefix . '_events';
        $this->workers = $prefix . '_workers';
    }

    public function metrics(string $worker, array $metrics): void
    {
        if ($metrics === []) {
            return;
        }
        $rows = [];
        foreach ($metrics as $metric) {
            // The hash tells names apart exactly, whatever the database thinks of upper and lower case.
            $rows[] = ['worker' => $worker, 'name_hash' => sha1($metric['name']), ...$metric];
        }
        $this->connection->table($this->metrics)->upsert(
            $rows,
            ['worker', 'kind', 'name_hash', 'bucket'],
            ['runs', 'total_ms', 'max_ms', 'errors'],
        );
    }

    public function events(array $events): void
    {
        if ($events === []) {
            return;
        }
        $rows = [];
        foreach ($events as $event) {
            $rows[] = [
                'kind' => $event['kind'],
                'at' => $event['at'],
                'title' => mb_substr($event['title'], 0, 250),
                'detail' => json_encode(
                    $event['detail'],
                    JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                ),
            ];
        }
        $this->connection->table($this->events)->insertMany($rows);
    }

    public function beat(array $worker): void
    {
        $this->connection->table($this->workers)->upsert($worker, 'id', ['seen_at', 'memory', 'handled']);
    }

    public function gone(string $worker): void
    {
        $this->connection->table($this->workers)->where('id', $worker)->delete();
    }

    public function totals(string $kind, int $since, int $limit = 300): array
    {
        $limit = max(1, $limit);
        $rows = $this->connection->select(
            "SELECT MIN(name) AS name, SUM(runs) AS runs, SUM(total_ms) AS total_ms, MAX(max_ms) AS max_ms, SUM(errors) AS errors, MAX(bucket) AS last FROM {$this->metrics} WHERE kind = ? AND bucket >= ? GROUP BY name_hash ORDER BY runs DESC LIMIT {$limit}",
            [$kind, $since],
        );
        return array_map(static fn(array $row): array => [
            'name' => (string) $row['name'],
            'runs' => (int) $row['runs'],
            'total_ms' => (float) $row['total_ms'],
            'max_ms' => (float) $row['max_ms'],
            'errors' => (int) $row['errors'],
            'last' => (int) $row['last'],
        ], $rows);
    }

    public function series(string $kind, int $since): array
    {
        $rows = $this->connection->select(
            "SELECT bucket, SUM(runs) AS runs, SUM(total_ms) AS total_ms, MAX(max_ms) AS max_ms, SUM(errors) AS errors FROM {$this->metrics} WHERE kind = ? AND bucket >= ? GROUP BY bucket ORDER BY bucket",
            [$kind, $since],
        );
        return array_map(static fn(array $row): array => [
            'bucket' => (int) $row['bucket'],
            'runs' => (int) $row['runs'],
            'total_ms' => (float) $row['total_ms'],
            'max_ms' => (float) $row['max_ms'],
            'errors' => (int) $row['errors'],
        ], $rows);
    }

    public function recent(string $kind, int $since, int $limit): array
    {
        $rows = $this->connection
            ->table($this->events)
            ->where('kind', $kind)
            ->where('at', '>=', $since)
            ->orderByDesc('at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
        return array_map(static fn(array $row): array => [
            'at' => (int) $row['at'],
            'title' => (string) $row['title'],
            'detail' => (array) json_decode((string) $row['detail'], true),
        ], $rows);
    }

    public function workers(int $seenSince): array
    {
        $rows = $this->connection
            ->table($this->workers)
            ->where('seen_at', '>=', $seenSince)
            ->orderBy('group_name')
            ->orderBy('started_at')
            ->get();
        return array_map(static fn(array $row): array => [
            'id' => (string) $row['id'],
            'group_name' => (string) $row['group_name'],
            'pid' => (int) $row['pid'],
            'host' => (string) $row['host'],
            'started_at' => (int) $row['started_at'],
            'seen_at' => (int) $row['seen_at'],
            'memory' => (int) $row['memory'],
            'handled' => (int) $row['handled'],
        ], $rows);
    }

    public function prune(int $before): void
    {
        $this->connection->table($this->metrics)->where('bucket', '<', $before)->delete();
        $this->connection->table($this->events)->where('at', '<', $before)->delete();
        // Jobs run by the thousand: of those kept one by one, only the latest are worth their place.
        $newest = $this->connection->table($this->events)->where('kind', 'job')->max('id');
        if ($newest !== null) {
            $this->connection
                ->table($this->events)
                ->where('kind', 'job')
                ->where('id', '<', (int) $newest - 5000)
                ->delete();
        }
        $this->connection->table($this->workers)->where('seen_at', '<', $before)->delete();
    }
}
