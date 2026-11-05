<?php

declare(strict_types=1);

namespace Crab\Clockwerk\Tests;

use Crab\Clockwerk\Secrets;
use PHPUnit\Framework\TestCase;

final class SecretsTest extends TestCase
{
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
