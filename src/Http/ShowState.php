<?php

declare(strict_types=1);

namespace Crab\Clockwerk\Http;

use Crab\Clockwerk\Dashboard;
use Crab\Http\Response;
use Crab\Routing\FromQuery;
use Crab\Validation\OneOf;

/**
 * What the dashboard shows, as JSON, for the minutes asked for.
 */
final readonly class ShowState
{
    public function __construct(
        private Dashboard $dashboard,
    ) {}

    public function handle(
        #[FromQuery]
        int $minutes = 60,
        #[FromQuery, OneOf('', 'pending', 'delayed', 'processing')]
        string $list = '',
    ): Response {
        return Response::json($this->dashboard->state($minutes, $list), headers: ['Cache-Control' => 'no-store']);
    }
}
