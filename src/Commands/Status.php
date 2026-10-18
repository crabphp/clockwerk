<?php

declare(strict_types=1);

namespace Crab\Clockwerk\Commands;

use Crab\Clockwerk\Dashboard;
use Crab\Contracts\Console\Command;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;

final readonly class Status implements Command
{
    public function __construct(
        private Dashboard $dashboard,
    ) {}

    public function description(): string
    {
        return <<<'TEXT'
            Show how the application is running: what the dashboard shows, in short
            Options:
              --minutes=60   How far back to count
            TEXT;
    }

    public function handle(Input $input, Output $output): int
    {
        $state = $this->dashboard->state((int) $input->option('minutes', '60'));
        $groups = [];
        foreach ($state['workers'] as $worker) {
            $groups[$worker['group_name']] = ($groups[$worker['group_name']] ?? 0) + 1;
        }
        $workers = [];
        foreach ($groups as $group => $count) {
            $workers[] = "{$count} {$group}";
        }
        $queues = $state['jobs']['queues'];
        $pending = array_sum(array_column($queues, 'pending')) + array_sum(array_column($queues, 'delayed'));
        $processing = array_sum(array_column($queues, 'processing'));
        $output->line("The last {$state['minutes']} minutes");
        $output->table([
            ['Workers', $workers === [] ? 'none heard from' : implode(', ', $workers)],
            ['Requests', self::counted($state['requests'])],
            [
                'Jobs',
                self::counted($state['jobs'])
                    . ", {$pending} pending, {$processing} processing, {$state['jobs']['failed_total']}"
                    . ($state['jobs']['failed_more'] ? '+' : '')
                    . ' failed for good',
            ],
            [
                'Tasks',
                count($state['tasks']['rows'])
                    . ' scheduled, '
                    . array_sum(array_column($state['tasks']['rows'], 'runs'))
                    . ' runs, '
                    . array_sum(array_column($state['tasks']['rows'], 'errors'))
                    . ' failed',
            ],
            ['Exceptions', (string) count($state['exceptions'])],
        ], indent: '  ');
        return 0;
    }

    /**
     * @param array{total: int, errors: int, avg_ms: float, max_ms: float} $measured
     */
    private static function counted(array $measured): string
    {
        return "{$measured['total']}, {$measured['errors']} failed, {$measured['avg_ms']} ms on average, {$measured['max_ms']} ms at most";
    }
}
