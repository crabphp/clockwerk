<?php

declare(strict_types=1);

namespace Crab\Clockwerk\Http;

use Crab\Http\Response;
use Crab\Clockwerk\Dashboard;

/**
 * What the dashboard shows, as JSON, for the minutes asked for.
 */
final readonly class ShowState
{
    public function __construct(private Dashboard $dashboard)
    {
    }

    public function handle(int $minutes = 60): Response
    {
        return Response::json($this->dashboard->state($minutes), headers: ['Cache-Control' => 'no-store']);
    }
}
