<?php

declare(strict_types=1);

namespace Crab\Clockwerk\Http;

use Crab\Contracts\Config\Config;
use Crab\Contracts\Http\Handler;
use Crab\Contracts\Http\Middleware;
use Crab\Contracts\Http\Request;
use Crab\Http\ErrorResponse;
use Psr\Http\Message\ResponseInterface;

/**
 * Lets in whoever knows the dashboard's token, "clockwerk.token", and no one
 * while there is none. The token is given as "Authorization: Bearer ..."
 * or, to open the page in a browser, as "?token=...".
 *
 * It guards the dashboard unless "clockwerk.middleware" names middleware of
 * the application's own to do so.
 */
final readonly class Gate implements Middleware
{
    private ?string $token;

    public function __construct(Config $config)
    {
        $token = $config->get('clockwerk.token');
        $this->token = is_string($token) && $token !== '' ? $token : null;
    }

    public function handle(Request $request, Handler $next): ResponseInterface
    {
        if ($this->token === null) {
            return ErrorResponse::make(
                $request,
                403,
                "The dashboard is closed: it shows how the application runs, and nothing says who may see it.\n\nSet \"clockwerk.token\" to a secret and open this page with ?token=<the secret>, or name your own middleware in \"clockwerk.middleware\".\n",
                ['Cache-Control' => 'no-store'],
            );
        }
        $given = $request->getQueryParams()['token'] ?? null;
        if (preg_match('/^Bearer\s+(.+)$/i', $request->getHeaderLine('Authorization'), $match) === 1) {
            $given = $match[1];
        }
        if (!is_string($given) || !hash_equals($this->token, $given)) {
            return ErrorResponse::make(
                $request,
                401,
                "The dashboard needs its token: open it with ?token=<the secret in \"clockwerk.token\">.\n",
                ['Cache-Control' => 'no-store', 'WWW-Authenticate' => 'Bearer'],
            );
        }
        return $next->handle($request);
    }
}
