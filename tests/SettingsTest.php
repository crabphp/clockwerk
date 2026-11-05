<?php

declare(strict_types=1);

namespace Crab\Clockwerk\Tests;

use Crab\App;
use Crab\Clockwerk\ClockwerkPlugin;
use Crab\Clockwerk\Recorder;
use Crab\Clockwerk\Store;
use Crab\Config\Config;
use Crab\Contracts\Config\InvalidSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The dashboard's settings are checked with the application's.
 */
final class SettingsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function invalidSettings(): iterable
    {
        foreach ([
            'path' => 'clockwerk',
            'token' => '',
            'middleware' => 'AdminsOnly',
            'name' => false,
            'table' => "invalid'prefix",
            'database' => '',
            'interval' => INF,
            'keep' => 0,
            'hide' => [''],
        ] as $name => $value) {
            yield $name => [$name, $value];
        }
    }

    #[DataProvider('invalidSettings')]
    public function testEverySettingRejectsAnInvalidValue(string $name, mixed $value): void
    {
        $app = new App(new Config());
        $app->register(ClockwerkPlugin::class);
        $config = new Config(['clockwerk' => [$name => $value]]);
        $config->defaults('clockwerk', $app->config->get('clockwerk'), $app->config->rules()['clockwerk']);

        self::assertCount(1, $config->problems('clockwerk'));
        self::assertStringStartsWith('clockwerk.' . $name, $config->problems('clockwerk')[0]);
    }

    public function testReplacingTheStoreDoesNotBypassTheRecordersSettings(): void
    {
        $app = new App(new Config(['clockwerk' => ['keep' => 0]]));
        $app->register(ClockwerkPlugin::class);
        $app->container->instance(Store::class, $this->createStub(Store::class));
        $this->expectException(InvalidSettings::class);
        $app->container->get(Recorder::class);
    }

    public function testTheDefaultsFitAndAWrongSettingIsNamedWithWhatItTakes(): void
    {
        $app = new App(new Config());
        $app->register(ClockwerkPlugin::class);
        self::assertSame([], $app->config->problems());

        $app = new App(new Config(['clockwerk' => [
            'token' => 'swordfish',
            'interval' => 0.5,
            'keep' => '24h',
        ]]));
        $app->register(ClockwerkPlugin::class);
        self::assertSame(
            [
                'clockwerk.interval is 0.5; it takes a number, at least 1.',
                'clockwerk.keep is "24h"; it takes a whole number, at least 1.',
            ],
            $app->config->problems(),
        );
    }
}
