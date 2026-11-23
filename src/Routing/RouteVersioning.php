<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiVersioning\Routing;

use Closure;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use InvalidArgumentException;
use LogicException;
use Pharaonic\Laravel\ApiVersioning\Middleware\AddVersionHeaders;
use Pharaonic\Laravel\ApiVersioning\Version;
use Pharaonic\Laravel\ApiVersioning\VersionRegistry;
use ReflectionMethod;

/**
 * Version metadata of routes, kept in the `api_versioning` route action key
 * as plain strings and arrays, so it survives route:cache unchanged.
 *
 * Constraints only ever narrow: nested groups and route-level calls
 * intersect, and a route that ends up in no version is a registration error.
 *
 * @internal
 *
 * @phpstan-type Metadata array{only: list<string>|null, since: string|null, until: string|null, path: string|null, controllers: array<string, string>|null, action: string|null, fallback: bool}
 * @phpstan-type Constraints array{only?: list<string>, since?: string, until?: string, path?: string}
 */
final class RouteVersioning
{
    public const ACTION = 'api_versioning';

    private const EMPTY = ['only' => null, 'since' => null, 'until' => null, 'path' => null, 'controllers' => null, 'action' => null, 'fallback' => false];

    /** @var array<string, Route> versioned routes by "METHOD domain/uri", to find duplicates */
    private array $tracked = [];

    /** Whether a Route::version() group is being registered right now. */
    private bool $inVersionGroup = false;

    public function __construct(private VersionRegistry $registry)
    {
    }

    /**
     * @return Metadata|null
     */
    public static function metadata(Route $route): ?array
    {
        $metadata = $route->action[self::ACTION] ?? null;

        if (! is_array($metadata)) {
            return null;
        }

        /** @var Metadata $metadata */
        return $metadata + self::EMPTY;
    }

    /**
     * @param  Constraints  $constraints
     */
    public function constrain(Route $route, array $constraints): void
    {
        $metadata = self::metadata($route) ?? self::EMPTY;

        foreach ($constraints['only'] ?? [] as $version) {
            $this->registry->get($version);
        }

        if (isset($constraints['only'])) {
            $metadata['only'] = $metadata['only'] === null
                ? $this->ordered($constraints['only'])
                : $this->ordered(array_intersect($metadata['only'], $constraints['only']));
        }

        if (isset($constraints['since'])) {
            $since = $this->registry->get($constraints['since'])->name();
            $metadata['since'] = $metadata['since'] === null || $this->registry->compare($since, $metadata['since']) > 0 ? $since : $metadata['since'];
        }

        if (isset($constraints['until'])) {
            $until = $this->registry->get($constraints['until'])->name();
            $metadata['until'] = $metadata['until'] === null || $this->registry->compare($until, $metadata['until']) < 0 ? $until : $metadata['until'];
        }

        if (isset($constraints['path'])) {
            if ($metadata['path'] !== null && $metadata['path'] !== $constraints['path']) {
                throw new LogicException(sprintf(
                    'Route [%s] is registered under the path prefixes of API versions [%s] and [%s]; Route::version() groups cannot be nested.',
                    self::describe($route), $metadata['path'], $constraints['path'],
                ));
            }

            $metadata['path'] = $this->registry->get($constraints['path'])->name();
        }

        $this->store($route, $metadata);
    }

    /**
     * @param  array<array-key, mixed>  $controllers
     */
    public function controllers(Route $route, array $controllers, bool $fallback): void
    {
        $metadata = self::metadata($route);

        if ($metadata !== null && $metadata['controllers'] !== null) {
            throw new LogicException(sprintf('Route [%s] already has version controllers.', self::describe($route)));
        }

        if ($controllers === []) {
            throw new InvalidArgumentException(sprintf('Route [%s] needs at least one version controller.', self::describe($route)));
        }

        $namespace = $route->getAction('namespace');
        $map = [];

        foreach ($controllers as $version => $action) {
            $map[$this->registry->get((string) $version)->name()] = self::controllerAction($route, $action, is_string($namespace) ? $namespace : null);
        }

        // The route's own action is restored after every request, and serves
        // unmapped versions when the route falls back to it.
        $uses = $route->action['uses'] ?? null;

        if (! is_string($uses) || ! str_contains($uses, '@')) {
            throw new InvalidArgumentException(sprintf(
                'Route [%s] needs a controller action to map version controllers; closures cannot be cached or restored.', self::describe($route),
            ));
        }

        $metadata ??= self::EMPTY;
        $metadata['controllers'] = [];

        foreach ($this->registry->all() as $version) {
            if (isset($map[$version])) {
                $metadata['controllers'][$version] = $map[$version];
            }
        }

        $metadata['action'] = $uses;
        $metadata['fallback'] = $fallback;

        $this->store($route, $metadata);
    }

    /**
     * Register routes into an empty collection, then version them and add them
     * to the router's collection, rejecting duplicate URIs and names.
     *
     * @param  Constraints  $constraints
     * @param  bool  $versionGroup  whether this is a Route::version() group, which cannot be nested
     */
    public function group(Router $router, array $constraints, Closure $register, bool $versionGroup = false): void
    {
        $collection = $router->getRoutes();

        if (! $collection instanceof RouteCollection) {
            throw new LogicException('API version groups cannot be registered while the route cache is loaded.');
        }

        if ($versionGroup && $this->inVersionGroup) {
            throw new LogicException('Route::version() groups cannot be nested: a route belongs to one version group.');
        }

        $captured = new CapturingRouteCollection;
        $router->setRoutes($captured);
        $outer = $this->inVersionGroup;
        $this->inVersionGroup = $outer || $versionGroup;

        try {
            $register();
        } finally {
            $router->setRoutes($collection);
            $this->inVersionGroup = $outer;
        }

        $collection->refreshNameLookups();
        $names = [];

        // RouteCollection::add() only accepts routes; Laravel 6 documents getRoutes() as a plain array.
        /** @var list<Route> $routes */
        $routes = array_values($captured->getRoutes());

        foreach ($routes as $route) {
            $this->constrain($route, $constraints);
            $name = $route->getName();

            if ($name !== null && $name !== '') {
                if (isset($names[$name]) || $collection->getByName($name) !== null) {
                    throw new LogicException(sprintf(
                        'Route name [%s] is already registered. Name the routes of each API version differently, for example with Route::version(...)->name(...) or [path.name].',
                        $name,
                    ));
                }

                $names[$name] = true;
            }

            if (($existing = self::conflict($collection, $route)) !== null) {
                throw self::duplicate($route, $existing);
            }

            $collection->add($route);
        }

        $collection->refreshNameLookups();
        $collection->refreshActionLookups();
    }

    public function available(Route $route, Version $version): bool
    {
        $metadata = self::metadata($route);

        if ($metadata === null || ! $this->allows($metadata, $version->name())) {
            return false;
        }

        return $metadata['controllers'] === null
            || isset($metadata['controllers'][$version->name()])
            || $metadata['fallback'];
    }

    /**
     * The controller action that serves the route in a version, when the route maps controllers per version.
     */
    public function handler(Route $route, Version $version): ?string
    {
        $metadata = self::metadata($route);

        if ($metadata === null || $metadata['controllers'] === null) {
            return null;
        }

        return $metadata['controllers'][$version->name()] ?? ($metadata['fallback'] ? $metadata['action'] : null);
    }

    /**
     * Point the route at a version's controller for the current request. Laravel
     * keeps the controller instance and the gathered middleware on the route, so
     * both are reset with it.
     */
    public static function point(Route $route, string $uses): void
    {
        $route->action['uses'] = $uses;
        $route->action['controller'] = $uses;
        $route->controller = null;
        $route->computedMiddleware = null;
    }

    /**
     * Point the route back at its own action once the request has left it.
     */
    public static function restore(Route $route): void
    {
        $metadata = self::metadata($route);

        if ($metadata !== null && $metadata['action'] !== null && ($route->action['uses'] ?? null) !== $metadata['action']) {
            self::point($route, $metadata['action']);
        }
    }

    /**
     * Fail when a later route definition replaced a versioned route with the same method and URI.
     */
    public function assertNotReplaced(RouteCollection $collection): void
    {
        foreach ($this->tracked as $key => $route) {
            [$method, $uri] = explode(' ', $key, 2);
            $current = self::routeAt($collection, $method, $uri);

            if ($current !== null && $current !== $route) {
                throw self::duplicate($current, $route);
            }
        }
    }

    public static function describe(Route $route): string
    {
        return implode('|', array_filter($route->methods(), 'is_string')).' '.$route->getDomain().'/'.ltrim($route->uri(), '/');
    }

    /**
     * @param  Metadata  $metadata
     */
    private function store(Route $route, array $metadata): void
    {
        $available = array_values(array_filter($this->registry->all(), function (string $version) use ($metadata): bool {
            return $this->allows($metadata, $version);
        }));

        if ($available === []) {
            throw new InvalidArgumentException(sprintf(
                'Route [%s] is not available in any API version: its version constraints do not overlap.', self::describe($route),
            ));
        }

        $unreachable = array_diff(array_keys($metadata['controllers'] ?? []), $available);

        if ($unreachable !== []) {
            throw new InvalidArgumentException(sprintf(
                'Route [%s] maps controllers to API versions [%s] it is not available in; it is available in [%s].',
                self::describe($route), implode(', ', $unreachable), implode(', ', $available),
            ));
        }

        $route->action[self::ACTION] = $metadata;

        // First of the route's middleware, so it wraps every other one, including
        // those that end the request early (bindings, authentication, throttling).
        // Laravel's priority sorting only reorders middleware of its priority list
        // among themselves, so it stays first.
        $route->action['middleware'] = array_merge([AddVersionHeaders::class], array_values(array_filter(
            (array) ($route->action['middleware'] ?? []),
            fn (mixed $middleware): bool => $middleware !== AddVersionHeaders::class,
        )));

        $this->track($route);
    }

    /**
     * @param  Metadata  $metadata
     */
    private function allows(array $metadata, string $version): bool
    {
        return ($metadata['only'] === null || in_array($version, $metadata['only'], true))
            && ($metadata['path'] === null || $metadata['path'] === $version)
            && ($metadata['since'] === null || $this->registry->compare($version, $metadata['since']) >= 0)
            && ($metadata['until'] === null || $this->registry->compare($version, $metadata['until']) <= 0);
    }

    private function track(Route $route): void
    {
        foreach (array_filter($route->methods(), 'is_string') as $method) {
            $key = $method.' '.$route->getDomain().$route->uri();
            $existing = $this->tracked[$key] ?? null;

            if ($existing !== null && $existing !== $route) {
                throw self::duplicate($route, $existing);
            }

            $this->tracked[$key] = $route;
        }
    }

    /**
     * @param  array<array-key, string>  $versions
     * @return list<string>
     */
    private function ordered(array $versions): array
    {
        return array_values(array_intersect($this->registry->all(), $versions));
    }

    private static function conflict(RouteCollection $collection, Route $route): ?Route
    {
        $key = $route->getDomain().$route->uri();

        foreach ($route->methods() as $method) {
            $existing = self::routeAt($collection, $method, $key);

            if ($existing instanceof Route) {
                return $existing;
            }
        }

        return null;
    }

    private static function routeAt(RouteCollection $collection, mixed $method, string $key): ?Route
    {
        $routes = is_string($method) ? ($collection->getRoutesByMethod()[$method] ?? null) : null;
        $route = is_array($routes) ? ($routes[$key] ?? null) : null;

        return $route instanceof Route ? $route : null;
    }

    private static function duplicate(Route $route, Route $existing): LogicException
    {
        return new LogicException(sprintf(
            'Route [%s] is registered more than once (%s and %s). Serve every API version of an endpoint from one route, with ->versions(), ->sinceVersion(), ->untilVersion() or ->controllers(), or give each version its own path prefix.',
            self::describe($route),
            self::versionsOf($existing),
            self::versionsOf($route),
        ));
    }

    private static function versionsOf(Route $route): string
    {
        $uses = $route->action['uses'] ?? null;
        $action = is_string($uses) ? $uses : 'Closure';
        $metadata = self::metadata($route);

        if ($metadata === null) {
            return $action.' without version constraints';
        }

        $parts = [];

        foreach (['path' => 'version', 'only' => 'versions', 'since' => 'since', 'until' => 'until'] as $key => $label) {
            $value = $metadata[$key];

            if ($value !== null) {
                $parts[] = $label.' '.(is_array($value) ? implode(', ', $value) : $value);
            }
        }

        return $action.($parts === [] ? '' : ' in '.implode('; ', $parts));
    }

    private static function controllerAction(Route $route, mixed $action, ?string $namespace): string
    {
        if (is_array($action) && count($action) === 2 && is_string($action[0] ?? null) && is_string($action[1] ?? null)) {
            [$class, $method] = [$action[0], $action[1]];
        } elseif (is_string($action) && $action !== '') {
            // Laravel prefixes string actions with the group namespace, but never [Class::class, 'method'] arrays.
            if ($namespace !== null && $namespace !== '' && ! str_starts_with($action, '\\')) {
                $action = $namespace.'\\'.$action;
            }

            [$class, $method] = str_contains($action, '@') ? explode('@', $action, 2) : [$action, '__invoke'];
        } else {
            throw new InvalidArgumentException(sprintf(
                'Version controllers of route [%s] must be "Class@method", [Class::class, "method"] or an invokable class name.', self::describe($route),
            ));
        }

        $class = ltrim($class, '\\');

        if (! class_exists($class) || ! method_exists($class, $method) || ! (new ReflectionMethod($class, $method))->isPublic()) {
            throw new InvalidArgumentException(sprintf(
                'Version controller [%s@%s] of route [%s] does not exist or is not a public method.', $class, $method, self::describe($route),
            ));
        }

        return $class.'@'.$method;
    }
}
