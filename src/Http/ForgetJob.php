<?php

declare(strict_types=1);

namespace Crab\Clockwerk\Http;

use Crab\Contracts\Queue\Store;
use Crab\Validation\Data;
use Crab\Validation\Fields;

/**
 * Deletes a failed job; every failed job when the id is "all". As
 * `queue:forget` does.
 */
final readonly class ForgetJob
{
    public function __construct(
        private Store $store,
    ) {}

    /**
     * @return array{forgotten: int}
     */
    public function handle(#[Fields(['id' => 'string'])] Data $job): array
    {
        $id = $job->id;

        return ['forgotten' => $this->store->forget($id === 'all' ? null : $id)];
    }
}
