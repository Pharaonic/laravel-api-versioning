<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiVersioning\Routing;

use BadMethodCallException;
use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Routing\RouteRegistrar;
use InvalidArgumentException;
use LogicException;
use Pharaonic\Laravel\ApiVersioning\VersionRegistry;

/**
 * What Route::version(), Route::versions(), Route::sinceVersion() and
 * Route::untilVersion() return: Laravel's own route registrar plus version
 * constraints. Group attributes (middleware, prefix, name, domain, where, …)
 * are passed to a native RouteRegistrar, so they keep Laravel's semantics.
 *
 * @method $this as(string $value)
 * @method $this domain(string $value)
 * @method $this middleware(array<array-key, string>|string|null $middleware)
 * @method $this name(string $value)
 * @method $this namespace(string|null $value)
 * @method $this prefix(string $prefix)
 * @method $this where(array<string, string> $where)
 */
final class VersionedRouteRegistrar
{
    /** Methods of Laravel's registrar that register routes, which only work inside group() here. */
    private const REGISTERING = [
        'resource', 'resources', 'apiResource', 'apiResources', 'singleton', 'singletons', 'apiSingleton', 'apiSingletons',
        'redirect', 'permanentRedirect', 'view', 'fallback',
    ];

    private const VERBS = ['get', 'post', 'put', 'patch', 'delete', 'options', 'any'];

    private RouteRegistrar $native;

    private ?string $version = null;

    /** @var array{only?: list<string>, since?: string, until?: string} */
    private array $constraints = [];

    public function __construct(
        private Router $router,
        private RouteVersioning $versioning,
        private VersionRegistry $registry,
        private Repository $config,
    ) {
        $this->native = new RouteRegistrar($router);
    }

    /**
     * Routes that belong to one API version. When [path.prefix] or [path.name]
     * is configured, the version's prefix and name prefix are added inside
     * every prefix and name already in effect.
     */
    public function version(string $version): self
    {
        if ($this->version !== null) {
            throw new LogicException('An API version group belongs to exactly one version.');
        }

        $this->version = $this->registry->get($version)->name();

        return $this;
    }

    /**
     * @param  string|array<array-key, mixed>  ...$versions
     */
    public function versions(string|array ...$versions): self
    {
        $only = self::list($versions, $this->registry);
        $this->constraints['only'] = isset($this->constraints['only']) ? array_values(array_intersect($this->constraints['only'], $only)) : $only;

        return $this;
    }

    public function sinceVersion(string $version): self
    {
        $this->constraints['since'] = $this->registry->get($version)->name();

        return $this;
    }

    public function untilVersion(string $version): self
    {
        $this->constraints['until'] = $this->registry->get($version)->name();

        return $this;
    }

    /**
     * @param  Closure|string  $routes  a closure or a route file, as for Route::group()
     */
    public function group(Closure|string $routes): void
    {
        $constraints = $this->constraints;
        $template = [];

        if ($this->version !== null) {
            $constraints['only'] = [$this->version];
            $prefix = $this->template('path.prefix');
            $name = $this->template('path.name');

            if ($prefix !== null) {
                $template['prefix'] = $prefix;
                $constraints['path'] = $this->version;
            }

            if ($name !== null) {
                $template['as'] = $name;
            }
        }

        $this->versioning->group($this->router, $constraints, function () use ($routes, $template): void {
            $this->native->group($template === [] ? $routes : function () use ($routes, $template): void {
                $this->router->group($template, $routes);
            });
        }, $this->version !== null);
    }

    /**
     * @param  array<array-key, string>|string  $methods
     * @param  array<array-key, mixed>|string|callable|null  $action
     */
    public function match(array|string $methods, string $uri, array|string|callable|null $action = null): Route
    {
        return $this->route('match', [$methods, $uri, $action]);
    }

    /**
     * @param  array<array-key, mixed>  $parameters
     * @return $this|Route
     */
    public function __call(string $method, array $parameters): self|Route
    {
        if (in_array($method, self::VERBS, true)) {
            return $this->route($method, $parameters);
        }

        if (in_array($method, self::REGISTERING, true)) {
            throw new BadMethodCallException(sprintf('Call Route::%s() inside ->group() of an API version registrar.', $method));
        }

        if ($this->native->{$method}(...$parameters) !== $this->native) {
            throw new BadMethodCallException(sprintf('Method %s::%s() is not supported.', self::class, $method));
        }

        return $this;
    }

    /**
     * Validated version names, in the order given, without duplicates.
     *
     * @param  array<array-key, mixed>  $versions
     * @return list<string>
     */
    public static function list(array $versions, VersionRegistry $registry): array
    {
        $names = [];

        array_walk_recursive($versions, function (mixed $version) use (&$names, $registry): void {
            if (! is_string($version)) {
                throw new InvalidArgumentException('API versions must be given as version names.');
            }

            if (in_array($version, $names, true)) {
                throw new InvalidArgumentException(sprintf('API version [%s] is listed more than once.', $version));
            }

            $names[] = $registry->get($version)->name();
        });

        if ($names === []) {
            throw new InvalidArgumentException('List at least one API version.');
        }

        return $names;
    }

    /**
     * @param  array<array-key, mixed>  $parameters
     */
    private function route(string $method, array $parameters): Route
    {
        $route = null;

        $this->group(function () use ($method, $parameters, &$route): void {
            $route = $this->router->{$method}(...$parameters);
        });

        assert($route instanceof Route);

        return $route;
    }

    private function template(string $key): ?string
    {
        $template = $this->config->get('pharaonic.api-versioning.'.$key);

        if ($template === null || $template === '') {
            return null;
        }

        if (! is_string($template) || ! str_contains($template, '{version}')) {
            throw new InvalidArgumentException(sprintf('[pharaonic.api-versioning.%s] must contain "{version}".', $key));
        }

        return str_replace('{version}', (string) $this->version, $template);
    }
}
