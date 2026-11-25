<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiVersioning;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Pharaonic\Laravel\ApiVersioning\Routing\PrepareVersionedRoute;
use Pharaonic\Laravel\ApiVersioning\Routing\RouteVersioning;
use Pharaonic\Laravel\ApiVersioning\Support\Macros;
use Pharaonic\Laravel\ApiVersioning\Support\VersionNegotiator;

final class ApiVersioningServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/api-versioning.php', 'pharaonic.api-versioning');

        // Built from configuration only; none of them keeps request state.
        $this->app->singleton(VersionRegistry::class, function (Container $app): VersionRegistry {
            /** @var Repository $config */
            $config = $app->make('config');

            return VersionRegistry::fromConfig($config);
        });

        $this->app->singleton(RouteVersioning::class);
        $this->app->singleton(VersionNegotiator::class);

        $this->app->singleton(VersionedResources::class, function (Container $app): VersionedResources {
            /** @var Repository $config */
            $config = $app->make('config');
            $fallback = $config->get('pharaonic.api-versioning.resource_fallback', VersionedResources::FALLBACK_NONE);

            if (! is_string($fallback)) {
                throw new InvalidArgumentException('[pharaonic.api-versioning.resource_fallback] must be "none", "base" or "previous".');
            }

            /** @var VersionRegistry $registry */
            $registry = $app->make(VersionRegistry::class);

            return new VersionedResources($registry, $fallback);
        });
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'api-versioning');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/api-versioning.php' => $this->app->configPath('pharaonic/api-versioning.php'),
            ], ['pharaonic', 'laravel-api-versioning', 'pharaonic-config', 'api-versioning-config']);

            $this->publishes([
                __DIR__.'/../resources/lang' => $this->app->resourcePath('lang/vendor/api-versioning'),
            ], ['pharaonic', 'laravel-api-versioning', 'pharaonic-translations', 'api-versioning-translations']);
        }

        Macros::register();

        /** @var Dispatcher $events */
        $events = $this->app->make('events');
        $events->listen(RouteMatched::class, PrepareVersionedRoute::class.'@handle');

        // Once the application's routes are loaded, a plain route registered
        // after a versioned one with the same method and URI would silently
        // replace it; fail instead, which also stops route:cache.
        $this->app->booted(function (): void {
            /** @var Router $router */
            $router = $this->app->make('router');
            $routes = $router->getRoutes();

            if ($routes instanceof RouteCollection && $this->app->resolved(RouteVersioning::class)) {
                /** @var RouteVersioning $versioning */
                $versioning = $this->app->make(RouteVersioning::class);
                $versioning->assertNotReplaced($routes);
            }
        });
    }
}
