<?php

declare(strict_types=1);

namespace Crab\Clockwerk\Http;

use Crab\Contracts\Config\Config;
use Crab\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Lets in whoever knows the dashboard's token, "clockwerk.token", and no one
 * while there is none. The token is given as "Authorization: Bearer ..."
 * or, to open the page in a browser, as "?token=...".
 *
 * It guards the dashboard unless "clockwerk.middleware" names middleware of
 * the application's own to do so.
 */
final readonly class Gate implements MiddlewareInterface
{
    private ?string $token;

    public function __construct(Config $config)
    {
        $token = $config->get('clockwerk.token');
        $this->token = is_string($token) && $token !== '' ? $token : null;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->token === null) {
            return Response::text("The dashboard is closed: it shows how the application runs, and nothing says who may see it.\n\nSet \"clockwerk.token\" to a secret and open this page with ?token=<the secret>, or name your own middleware in \"clockwerk.middleware\".\n", 403);
        }
        $given = $request->getQueryParams()['token'] ?? null;
        if (preg_match('/^Bearer\s+(.+)$/i', $request->getHeaderLine('Authorization'), $match) === 1) {
            $given = $match[1];
        }
        if (!is_string($given) || !hash_equals($this->token, $given)) {
            return Response::text("The dashboard needs its token: open it with ?token=<the secret in \"clockwerk.token\">.\n", 401);
        }
        return $handler->handle($request);
    }
}
