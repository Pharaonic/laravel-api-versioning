<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiVersioning\Routing;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Routing\Events\RouteMatched;
use Pharaonic\Laravel\ApiVersioning\Exceptions\ApiVersionException;
use Pharaonic\Laravel\ApiVersioning\Support\CurrentVersion;
use Pharaonic\Laravel\ApiVersioning\Support\VersionNegotiator;
use Pharaonic\Laravel\ApiVersioning\VersionRegistry;

/**
 * Runs when the router has matched a versioned route, before it gathers the
 * route's middleware: resolves the version, rejects requests the route
 * cannot serve, and points the route at the version's controller.
 *
 * Laravel reuses the matched Route object for every request of a worker and
 * stores per-request state on it (its parameters, its controller instance).
 * The handler is set the same way, on every request, before anything reads
 * it, and the cached controller and middleware are reset with it; the
 * AddVersionHeaders middleware points the route back at its own action when
 * the request leaves it. From there Laravel's own pipeline runs unchanged:
 * controller middleware, constructor and method injection, implicit and
 * explicit binding, authorization.
 *
 * @internal
 */
final class PrepareVersionedRoute
{
    public function __construct(
        private RouteVersioning $versioning,
        private VersionNegotiator $negotiator,
        private VersionRegistry $registry,
        private Repository $config,
    ) {
    }

    /**
     * @throws ApiVersionException
     */
    public function handle(RouteMatched $event): void
    {
        $route = $event->route;

        if (RouteVersioning::metadata($route) === null) {
            return;
        }

        $request = $event->request;
        $version = $this->negotiator->negotiate($request);

        CurrentVersion::set($request, $version);

        // The version segment is routing data, never a controller argument or a binding.
        $parameter = $this->config->get('pharaonic.api-versioning.path.parameter', 'version');

        if (is_string($parameter) && $route->hasParameter($parameter)) {
            $route->forgetParameter($parameter);
        }

        if ($this->registry->status($version) === VersionRegistry::RETIRED) {
            throw $this->negotiator->reject(ApiVersionException::RETIRED, $this->registry->lifecycleHeaders($version));
        }

        if (! $this->versioning->available($route, $version)) {
            throw $this->negotiator->reject(ApiVersionException::UNAVAILABLE);
        }

        $handler = $this->versioning->handler($route, $version);

        if ($handler !== null) {
            RouteVersioning::point($route, $handler);
        }
    }
}
