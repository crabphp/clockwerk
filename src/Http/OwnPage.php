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
 * Lets a request that changes something through only when the
 * dashboard's own page sent it. Where the dashboard is guarded by a
 * cookie, another site could otherwise have a logged-in visitor's
 * browser retry or forget jobs for it.
 *
 * The page sends JSON, which a form on another site cannot, and a
 * browser says where a request comes from, which must be here.
 */
final readonly class OwnPage implements Middleware
{
    private ?string $url;

    public function __construct(Config $config)
    {
        $url = $config->get('app.url');
        $this->url = is_string($url) && $url !== '' ? $url : null;
    }

    public function handle(Request $request, Handler $next): ResponseInterface
    {
        $type = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'), 2)[0]));
        $site = strtolower($request->getHeaderLine('Sec-Fetch-Site'));
        $origin = $request->getHeaderLine('Origin');
        $elsewhere = !in_array($site, ['', 'same-origin', 'none'], true);
        // Fetch Metadata describes the browser's public origin, including behind a TLS proxy.
        // Older browsers need the public app.url there; never trust raw forwarding headers.
        if ($origin !== '' && ($site !== 'same-origin' || $this->url !== null)) {
            $given = parse_url($origin);
            $address =
                $this->url ?? ($request->getUri()->getScheme() ?: 'http') . '://' . $request->getHeaderLine('Host');
            $expected = self::origin(parse_url($address));
            $elsewhere =
                $elsewhere
                || $given === false
                || array_diff(array_keys($given), ['scheme', 'host', 'port']) !== []
                || $expected === null
                || self::origin($given) !== $expected;
        }
        if ($type !== 'application/json' || $elsewhere) {
            return ErrorResponse::make($request, 403, 'This is done from the dashboard\'s own page.', [
                'Cache-Control' => 'no-store',
            ]);
        }
        return $next->handle($request);
    }

    /**
     * @param array<string, int|string>|false $parts
     * @return array{string, string, int}|null
     */
    private static function origin(array|false $parts): ?array
    {
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }
        return [$scheme, $host, (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80))];
    }
}
