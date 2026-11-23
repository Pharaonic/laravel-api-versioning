<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiVersioning\Support;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Traits\Macroable;
use LogicException;
use Pharaonic\Laravel\ApiVersioning\Routing\RouteVersioning;
use Pharaonic\Laravel\ApiVersioning\Routing\VersionedRouteRegistrar;
use Pharaonic\Laravel\ApiVersioning\Version;
use Pharaonic\Laravel\ApiVersioning\VersionRegistry;
use ReflectionFunction;
use ReflectionProperty;

/**
 * The package's macros. They are thin adapters: every one resolves the
 * package services from the current container when it is called, because
 * macros are static and outlive the application that registered them.
 *
 * Registering is idempotent. A macro or method with the same name from
 * anywhere else is never replaced: registration fails instead.
 *
 * @internal
 */
final class Macros
{
    public static function register(): void
    {
        self::router('version', function (string $version, Closure|string|null $routes = null): ?VersionedRouteRegistrar {
            $registrar = Macros::registrar($this)->version($version);

            if ($routes === null) {
                return $registrar;
            }

            $registrar->group($routes);

            return null;
        });

        /** @param  string|array<array-key, string>  ...$versions */
        self::router('versions', function (string|array ...$versions): VersionedRouteRegistrar {
            return Macros::registrar($this)->versions(...$versions);
        });

        self::router('sinceVersion', function (string $version): VersionedRouteRegistrar {
            return Macros::registrar($this)->sinceVersion($version);
        });

        self::router('untilVersion', function (string $version): VersionedRouteRegistrar {
            return Macros::registrar($this)->untilVersion($version);
        });

        /** @param  string|array<array-key, string>  ...$versions */
        self::route('versions', function (string|array ...$versions): Route {
            Macros::versioning()->constrain($this, ['only' => VersionedRouteRegistrar::list($versions, Macros::registry())]);

            return $this;
        });

        self::route('sinceVersion', function (string $version): Route {
            Macros::versioning()->constrain($this, ['since' => $version]);

            return $this;
        });

        self::route('untilVersion', function (string $version): Route {
            Macros::versioning()->constrain($this, ['until' => $version]);

            return $this;
        });

        self::route('controllers', function (array $controllers, bool $fallback = false): Route {
            Macros::versioning()->controllers($this, $controllers, $fallback);

            return $this;
        });

        self::request('apiVersion', function (): ?Version {
            return CurrentVersion::of($this);
        });

        // JsonResource is macroable from Laravel 9; on earlier versions the
        // InteractsWithApiVersion trait provides the same method.
        self::resource('whenVersion', function (string $expression, mixed $value, mixed $default = null): mixed {
            return VersionExpression::when($expression, $value, $default, func_num_args() >= 3);
        });
    }

    public static function registrar(Router $router): VersionedRouteRegistrar
    {
        /** @var VersionedRouteRegistrar */
        return Container::getInstance()->make(VersionedRouteRegistrar::class, ['router' => $router]);
    }

    public static function versioning(): RouteVersioning
    {
        /** @var RouteVersioning */
        return Container::getInstance()->make(RouteVersioning::class);
    }

    public static function registry(): VersionRegistry
    {
        /** @var VersionRegistry */
        return Container::getInstance()->make(VersionRegistry::class);
    }

    /**
     * @param-closure-this Router $macro
     */
    private static function router(string $name, Closure $macro): void
    {
        self::define(Router::class, $name, $macro);
    }

    /**
     * @param-closure-this Route $macro
     */
    private static function route(string $name, Closure $macro): void
    {
        self::define(Route::class, $name, $macro);
    }

    /**
     * @param-closure-this Request $macro
     */
    private static function request(string $name, Closure $macro): void
    {
        self::define(Request::class, $name, $macro);
    }

    /**
     * @param-closure-this JsonResource $macro
     */
    private static function resource(string $name, Closure $macro): void
    {
        self::define(JsonResource::class, $name, $macro);
    }

    /**
     * @param  class-string  $class
     */
    private static function define(string $class, string $name, Closure $macro): void
    {
        if (! in_array(Macroable::class, class_uses_recursive($class), true)) {
            return;
        }

        if (method_exists($class, $name)) {
            throw new LogicException(sprintf('Cannot register the %s::%s() macro: the method already exists.', $class, $name));
        }

        $property = new ReflectionProperty($class, 'macros');
        $property->setAccessible(true);
        $macros = $property->getValue();
        $existing = is_array($macros) ? ($macros[$name] ?? null) : null;

        if ($existing !== null && ! ($existing instanceof Closure && (new ReflectionFunction($existing))->getFileName() === __FILE__)) {
            throw new LogicException(sprintf(
                'Cannot register the %s::%s() macro: another package or the application already registered a macro with that name.', $class, $name,
            ));
        }

        $register = [$class, 'macro'];

        if (is_callable($register)) {
            $register($name, $macro);
        }
    }
}
