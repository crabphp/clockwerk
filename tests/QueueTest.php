<?php

declare(strict_types=1);

namespace Crab\Clockwerk\Tests;

use Crab\App;
use Crab\Clockwerk\ClockwerkPlugin;
use Crab\Clockwerk\Dashboard;
use Crab\Clockwerk\Store;
use Crab\Config\Config;
use Crab\Queue\QueuePlugin;
use PHPUnit\Framework\TestCase;

final class QueueTest extends TestCase
{
    public function testTheDefaultSupervisorOnlyServesItsConfiguredQueue(): void
    {
        $app = new App(new Config(['queue' => [
            'store' => 'memory',
            'queue' => 'mail',
            'workers' => 4,
            'concurrency' => 2,
        ]]));
        $app->register(QueuePlugin::class, ClockwerkPlugin::class);
        $app->container->instance(Store::class, $this->createStub(Store::class));

        self::assertSame(
            [
                ['name' => 'queue', 'queues' => ['mail'], 'workers' => 4, 'concurrency' => 2, 'tries' => 3],
            ],
            $app->container->get(Dashboard::class)->state()['jobs']['supervisors'],
        );
    }
}
