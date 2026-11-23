<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiVersioning\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Pharaonic\Laravel\ApiVersioning\Routing\RouteVersioning;
use Pharaonic\Laravel\ApiVersioning\Support\CurrentVersion;
use Pharaonic\Laravel\ApiVersioning\Support\VersionNegotiator;
use Pharaonic\Laravel\ApiVersioning\VersionRegistry;
use Symfony\Component\HttpFoundation\Response;

/**
 * Added to every versioned route. Points a route with version controllers
 * back at its own action when the request leaves it, extends Vary with the version header when
 * the header resolver is enabled, and adds the version's Deprecation, Sunset
 * and Link headers. Headers the application already set are kept; the body
 * is never touched.
 *
 * @internal
 */
final class AddVersionHeaders
{
    public function __construct(
        private VersionNegotiator $negotiator,
        private VersionRegistry $registry,
    ) {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        try {
            $response = $next($request);
        } finally {
            $route = $request->route();

            if ($route instanceof Route) {
                RouteVersioning::restore($route);
            }
        }

        if (! $response instanceof Response) {
            return $response;
        }

        $header = $this->negotiator->header();

        if ($header !== null && ! self::varies($response, $header)) {
            $response->setVary($header, false);
        }

        $version = CurrentVersion::of($request);

        if ($version === null) {
            return $response;
        }

        foreach ($this->registry->lifecycleHeaders($version) as $name => $value) {
            if ($name === 'Link') {
                $response->headers->set($name, $value, false);
            } elseif (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }

    private static function varies(Response $response, string $header): bool
    {
        foreach ($response->getVary() as $vary) {
            if (is_string($vary) && strcasecmp($vary, $header) === 0) {
                return true;
            }
        }

        return false;
    }
}
