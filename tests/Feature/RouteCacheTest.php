<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiVersioning\Tests\Feature;

use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Pharaonic\Laravel\ApiVersioning\Routing\RouteVersioning;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Controllers\InvokableV3Controller;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Controllers\ProfileController;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Controllers\UserController;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Controllers\UserV1Controller;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Controllers\UserV2Controller;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Middleware\Tag;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Models\Widget;
use Pharaonic\Laravel\ApiVersioning\Tests\TestCase;

/**
 * Writes the routes file exactly as route:cache does on the running Laravel
 * (serialized routes on Laravel 6, a compiled route collection from 7), then
 * boots a new application that loads only that file, and compares routes and
 * responses with the ones before caching.
 */
final class RouteCacheTest extends TestCase
{
    private string $file = '';

    protected function tearDown(): void
    {
        if ($this->file !== '' && is_file($this->file)) {
            unlink($this->file);
        }

        parent::tearDown();
    }

    public function test_versioned_routes_serve_identical_responses_from_cached_routes(): void
    {
        $this->versioning(['path.prefix' => 'api/{version}', 'path.name' => '{version}.']);
        $this->registerRoutes();
        Route::getRoutes()->refreshNameLookups();

        $before = $this->snapshot();
        $responses = $this->responses();

        $this->cacheRoutes();
        $this->refreshApplication();
        $this->versioning(['path.prefix' => 'api/{version}', 'path.name' => '{version}.']);

        $this->assertCount(0, Route::getRoutes()->getRoutes());
        require $this->file;

        $this->assertSame(
            method_exists(RouteCollection::class, 'compile') ? 'Illuminate\Routing\CompiledRouteCollection' : RouteCollection::class,
            get_class(Route::getRoutes()),
        );
        $this->assertSame($before, $this->snapshot());
        $this->assertSame($responses, $this->responses());

        // What the requests above must have produced, with and without the cache.
        $this->assertSame([200, ['controller' => 'v1', 'widget' => 1, 'name' => 'First'], 'v1,group'], $responses['header v1']);
        $this->assertSame([200, ['controller' => 'v2', 'widget' => 1, 'email' => 'first@example.com'], 'v2-show-only,v2,group'], $responses['header v2']);
        $this->assertSame([404, null, null], $responses['header v3']);
        $this->assertSame([403, null, 'v2-show-only,v2,group'], $responses['policy v2']);
        $this->assertSame([404, null, null], $responses['missing model']);
        $this->assertSame([200, ['controller' => 'v3'], 'group'], $responses['query invokable']);
        $this->assertSame(200, $responses['path v1'][0]);
        $this->assertSame([400, null, null], $responses['path conflict']);
        $this->assertSame('http://localhost/api/v2/users', $responses['named'][1]);
    }

    public function test_route_list_shows_cached_versioned_routes(): void
    {
        $this->versioning(['path.prefix' => 'api/{version}', 'path.name' => '{version}.']);
        $this->registerRoutes();
        $this->cacheRoutes();
        $this->refreshApplication();
        require $this->file;

        $this->assertSame(0, Artisan::call('route:list'));
        $this->assertStringContainsString('widgets/{widget}', Artisan::output());
    }

    public function test_the_configuration_survives_config_cache(): void
    {
        $this->versioning(['lifecycle' => ['v1' => ['status' => 'deprecated', 'deprecated_at' => '2026-06-01']]]);

        $config = config('pharaonic.api-versioning');
        $file = (string) tempnam(sys_get_temp_dir(), 'api-versioning-config');

        try {
            file_put_contents($file, '<?php return '.var_export($config, true).';'.PHP_EOL);
            $this->assertSame($config, require $file);
        } finally {
            unlink($file);
        }
    }

    private function registerRoutes(): void
    {
        Route::middleware([SubstituteBindings::class, Tag::class.':group'])->group(function (): void {
            Route::get('widgets/{widget}', [UserController::class, 'show'])->controllers([
                'v1' => [UserV1Controller::class, 'show'],
                'v2' => [UserV2Controller::class, 'show'],
            ]);
            Route::get('users', [UserController::class, 'index'])->name('users')->controllers([
                'v1' => UserV1Controller::class.'@index',
                'v3' => InvokableV3Controller::class,
            ]);
        });

        foreach (['v1' => UserV1Controller::class, 'v2' => UserV2Controller::class] as $version => $controller) {
            Route::version($version, function () use ($controller): void {
                Route::get('users', [$controller, 'index'])->name('users');
            });
        }

        Route::prefix('shared/{version}')->group(function (): void {
            Route::get('profile', ProfileController::class)->versions('v2', 'v3');
        });
    }

    /**
     * @return array<string, array{int, mixed, mixed}>
     */
    private function responses(): array
    {
        $this->createWidgets();
        Gate::define('view-widget', function (?\Illuminate\Auth\GenericUser $user, Widget $widget): bool {
            return $widget->id === 1;
        });

        $requests = [
            'header v1' => ['/widgets/1', ['X-API-Version' => 'v1']],
            'header v2' => ['/widgets/1', ['X-API-Version' => 'v2']],
            'header v3' => ['/widgets/1', ['X-API-Version' => 'v3']],
            'policy v2' => ['/widgets/2', ['X-API-Version' => 'v2']],
            'missing model' => ['/widgets/9', ['X-API-Version' => 'v1']],
            'query invokable' => ['/users?version=v3', []],
            'path v1' => ['/api/v1/users', []],
            'path v2' => ['/api/v2/users', []],
            'path conflict' => ['/api/v1/users', ['X-API-Version' => 'v2']],
            'shared' => ['/shared/v3/profile', []],
            'shared unavailable' => ['/shared/v1/profile', []],
        ];
        $results = [];

        foreach ($requests as $key => [$uri, $headers]) {
            $response = $this->getJson($uri, $headers);
            $results[$key] = [$response->getStatusCode(), $response->isSuccessful() ? $response->json() : null, $response->headers->get('X-Middleware')];
        }

        $results['named'] = [200, route('v2.users'), null];
        $this->app['db']->connection()->getSchemaBuilder()->drop('widgets');

        return $results;
    }

    /**
     * @return list<string>
     */
    private function snapshot(): array
    {
        $routes = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $this->assertInstanceOf(RoutingRoute::class, $route);
            // Compiling the route cache (Laravel 7+) names unnamed routes "generated::…".
            $name = str_starts_with((string) $route->getName(), 'generated::') ? '' : $route->getName();
            $routes[] = implode('|', $route->methods()).' '.$route->uri().' '.$name.' '.$route->getActionName()
                .' '.json_encode(RouteVersioning::metadata($route)).' '.json_encode($route->middleware());
        }

        sort($routes);

        return $routes;
    }

    private function cacheRoutes(): void
    {
        $routes = Route::getRoutes();
        $this->assertInstanceOf(RouteCollection::class, $routes);

        foreach ($routes as $route) {
            $this->assertInstanceOf(RoutingRoute::class, $route);
            $route->prepareForSerialization();
        }

        // RouteCacheCommand::buildRouteCacheFile() and its routes.stub, per Laravel version.
        $contents = method_exists($routes, 'compile')
            ? '<?php app(\'router\')->setCompiledRoutes('.var_export($routes->compile(), true).');'
            : '<?php app(\'router\')->setRoutes(unserialize(base64_decode(\''.base64_encode(serialize($routes)).'\')));';

        $this->file = (string) tempnam(sys_get_temp_dir(), 'api-versioning-routes');
        file_put_contents($this->file, $contents.PHP_EOL);
        $this->assertStringNotContainsString('Closure', $contents);
    }
}
