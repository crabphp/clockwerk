<?php

declare(strict_types=1);

namespace Crab\Clockwerk\Http;

use Crab\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Lets a request that changes something through only when the
 * dashboard's own page sent it. Where the dashboard is guarded by a
 * cookie, another site could otherwise have a logged-in visitor's
 * browser retry or forget jobs for it.
 *
 * The page sends JSON, which a form on another site cannot, and a
 * browser says where a request comes from, which must be here.
 */
final readonly class OwnPage implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $json = str_starts_with(strtolower($request->getHeaderLine('Content-Type')), 'application/json');
        $site = strtolower($request->getHeaderLine('Sec-Fetch-Site'));
        $origin = $request->getHeaderLine('Origin');
        $elsewhere = ($site !== '' && $site !== 'same-origin' && $site !== 'none')
            || ($origin !== '' && strtolower((string) parse_url($origin, PHP_URL_HOST)) !== strtolower((string) preg_replace('/:\d+$/', '', $request->getHeaderLine('Host'))));
        if (!$json || $elsewhere) {
            return Response::text("This is done from the dashboard's own page.\n", 403);
        }
        return $handler->handle($request);
    }
}
