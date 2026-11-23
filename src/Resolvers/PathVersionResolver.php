<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiVersioning\Resolvers;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Pharaonic\Laravel\ApiVersioning\Contracts\VersionResolver;
use Pharaonic\Laravel\ApiVersioning\Routing\RouteVersioning;

/**
 * Reads the version from the matched route: its version route parameter
 * (`{version}` by default) when it has one, otherwise the version whose
 * configured path prefix the route was registered under by Route::version().
 */
final class PathVersionResolver implements VersionResolver
{
    public function __construct(private string $parameter) {}

    public function resolve(Request $request): ?string
    {
        $route = $request->route();

        if (! $route instanceof Route) {
            return null;
        }

        if ($route->hasParameter($this->parameter)) {
            $value = $route->parameter($this->parameter);

            return is_string($value) ? $value : null;
        }

        return RouteVersioning::metadata($route)['path'] ?? null;
    }
}
