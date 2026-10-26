<?php

declare(strict_types=1);

namespace Crab\Clockwerk\Http;

use Crab\Contracts\Queue\Store;
use Crab\Validation\Data;
use Crab\Validation\Fields;

/**
 * Puts a failed job back to wait as if new; every failed job when the id
 * is "all". As `queue:retry` does.
 */
final readonly class RetryJob
{
    public function __construct(
        private Store $store,
    ) {}

    /**
     * @return array{retried: int}
     */
    public function handle(#[Fields(['id' => 'string'])] Data $job): array
    {
        $id = $job->id;

        return ['retried' => $this->store->retry($id === 'all' ? null : $id, time())];
    }
}
