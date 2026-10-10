<?php

declare(strict_types=1);

namespace Crab\Clockwerk\Http;

use Crab\Http\Response;

/**
 * The dashboard's page: one file, with its styles and script in it, that
 * asks ShowState for what to show.
 */
final class ShowDashboard
{
    private ?string $page = null;

    public function handle(): Response
    {
        $this->page ??= (string) file_get_contents(dirname(__DIR__, 2) . '/resources/dashboard.html');
        return Response::html($this->page, headers: ['Cache-Control' => 'no-store']);
    }
}
