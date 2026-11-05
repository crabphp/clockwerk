<?php

declare(strict_types=1);

namespace Crab\Clockwerk\Tests;

use Crab\App;
use Crab\Clockwerk\ClockwerkPlugin;
use Crab\Clockwerk\Commands\InstallClockwerk;
use Crab\Clockwerk\Commands\Status;
use Crab\Clockwerk\Dashboard;
use Crab\Clockwerk\Store;
use Crab\Config\Config;
use Crab\Console\Input;
use Crab\Container\Container;
use Crab\Contracts\Config\InvalidSettings;
use Crab\Contracts\Console\Output;
use Crab\Contracts\Database\Migrators;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CommandTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidMinutes(): iterable
    {
        foreach ([
            '--minutes',
            '--minutes=',
            '--minutes=abc',
            '--minutes=5m',
            '--minutes=5.5',
            '--minutes=1e2',
            '--minutes=4',
            '--minutes=1441',
            '--minutes=999999999999999999999999',
        ] as $option) {
            yield $option => [$option];
        }
    }

    #[DataProvider('invalidMinutes')]
    public function testInvalidMinutesAreRejectedBeforeReadingTheStore(string $option): void
    {
        $store = $this->createMock(Store::class);
        $store->expects(self::never())->method('series');
        $command = new Status(new Dashboard($store, new Config(), new Container()));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('--minutes');
        $command->handle(new Input([$option]), $this->createStub(Output::class));
    }

    public function testAnInvalidTablePrefixCannotWriteAMigration(): void
    {
        $app = new App(new Config(['clockwerk' => ['table' => "bad'prefix"]]));
        $app->register(ClockwerkPlugin::class);
        $migrators = $this->createMock(Migrators::class);
        $migrators->expects(self::never())->method('migrator');
        $command = new InstallClockwerk($migrators, $app->config);
        $this->expectException(InvalidSettings::class);
        $this->expectExceptionMessage('clockwerk.table');
        $command->handle(new Input(), $this->createStub(Output::class));
    }
}
