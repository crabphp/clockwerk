<?php

declare(strict_types=1);

namespace Crab\Clockwerk\Tests;

use Crab\Clockwerk\Dashboard;
use Crab\Clockwerk\Store;
use Crab\Config\Config;
use Crab\Container\Container;
use Crab\Contracts\Schedule\Schedule as ScheduleContract;
use Crab\Schedule\Schedule;
use PHPUnit\Framework\TestCase;

final class ScheduleTest extends TestCase
{
    public function testAnImpossibleScheduleDoesNotHideTheRestOfTheDashboard(): void
    {
        $schedule = new Schedule();
        $schedule
            ->call(static fn() => null)
            ->cron('0 0 31 2 *')
            ->name('impossible');
        $schedule
            ->call(static fn() => null)
            ->everyMinute()
            ->withoutOverlapping()
            ->name('valid');
        $container = new Container();
        $container->instance(ScheduleContract::class, $schedule);
        $dashboard = new Dashboard(
            $this->createStub(Store::class),
            new Config(['clockwerk' => ['interval' => 5]]),
            $container,
        );

        $state = $dashboard->state();
        self::assertSame(0, $state['requests']['total']);
        $rows = array_column($state['tasks']['rows'], null, 'name');
        self::assertNull($rows['impossible']['next']);
        self::assertStringContainsString('never comes', $rows['impossible']['next_error']);
        self::assertGreaterThan($state['now'], $rows['valid']['next']);
        self::assertNull($rows['valid']['next_error']);
        self::assertTrue($rows['valid']['without_overlapping']);
    }
}
