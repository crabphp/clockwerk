<?php

declare(strict_types=1);

namespace Crab\Clockwerk\Tests;

use Crab\Clockwerk\Recorder;
use Crab\Clockwerk\Secrets;
use Crab\Clockwerk\Store;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class SecretsTest extends TestCase
{
    /**
     * This isolated PHP process deliberately captures arguments; the host's php.ini must not decide the test.
     *
     * @mago-expect lint:no-ini-set
     */
    #[RunInSeparateProcess]
    public function testRecordedTracesExcludeArgumentsEvenWhenPhpCapturesThem(): void
    {
        ini_set('zend.exception_ignore_args', '0');
        $make = static fn(string $credential): \Throwable => new \RuntimeException('failed');
        $error = $make('PRIVATE-ARGUMENT');
        self::assertSame('PRIVATE-ARGUMENT', $error->getTrace()[0]['args'][0]);
        $events = [];
        $store = $this->createStub(Store::class);
        $store
            ->method('events')
            ->willReturnCallback(static function (array $rows) use (&$events): void {
                $events = $rows;
            });
        $recorder = new Recorder($store);
        $recorder->exception($error, 'test');
        $recorder->flush('worker');

        self::assertNotEmpty($events[0]['detail']['trace']);
        self::assertStringNotContainsString('PRIVATE-ARGUMENT', json_encode($events, JSON_THROW_ON_ERROR));
    }

    public function testOverlappingSecretValuesAreRemovedWholeWhateverTheirOrder(): void
    {
        $secrets = new Secrets(['token']);
        $data = ['token' => 'abcd', 'other_token' => 'abcdefgh', 'url_token' => 'a b/c'];
        self::assertSame('(hidden) (hidden) (hidden) (hidden)', $secrets->scrubbed(
            'abcdefgh abcd a%20b%2Fc a+b%2Fc',
            $data,
        ));
        self::assertSame('(hidden)', $secrets->scrubbed('abcdefgh', array_reverse($data)));
    }
}
