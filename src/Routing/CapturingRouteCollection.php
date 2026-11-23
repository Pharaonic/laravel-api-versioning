<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiVersioning\Routing;

use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use LogicException;

/**
 * The temporary collection a version group registers into. Laravel's own
 * collection silently replaces a route with the same method and URI; this
 * one refuses, so two definitions inside one group never shadow each other.
 *
 * @internal
 */
final class CapturingRouteCollection extends RouteCollection
{
    /**
     * @return Route
     */
    public function add(Route $route)
    {
        $key = $route->getDomain().$route->uri();

        foreach ($route->methods() as $method) {
            $routes = is_string($method) ? ($this->routes[$method] ?? null) : null;

            if (is_array($routes) && isset($routes[$key])) {
                throw new LogicException(sprintf(
                    'Route [%s] is registered more than once in the same API version group.', RouteVersioning::describe($route),
                ));
            }
        }

        return parent::add($route);
    }
}
