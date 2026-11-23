<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Routing\CompiledRouteCollection;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Pharaonic\Laravel\ApiVersioning\Routing\RouteVersioning;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Models\Widget;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\RouteCacheServiceProvider;
use Pharaonic\Laravel\ApiVersioning\Tests\TestCase;
use function Orchestra\Testbench\remote;

// route:cache runs for real in the Testbench CLI, which registers the fixture
// RouteCacheServiceProvider from testbench.yaml; the reloaded application then
// loads the compiled routes it wrote, and routes and responses are compared
// with the ones before caching. config:cache writes var_export() output.

function versionedRoutesFile(): string
{
    return __DIR__.'/../Fixtures/routes/versioned.php';
}

/**
 * @return array<string, array{int, mixed, mixed}>
 */
function versionedResponses(TestCase $test): array
{
    $test->createWidgets();
    Gate::define('view-widget', function (?GenericUser $user, Widget $widget): bool {
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
        $response = $test->getJson($uri, $headers);
        $results[$key] = [$response->getStatusCode(), $response->isSuccessful() ? $response->json() : null, $response->headers->get('X-Middleware')];
    }

    $results['named'] = [200, route('v2.users'), null];
    app('db')->connection()->getSchemaBuilder()->drop('widgets');

    return $results;
}

/**
 * Every registered route with its name, action, versioning metadata and middleware, sorted.
 *
 * @return list<string>
 */
function versionedRouteSnapshot(): array
{
    $routes = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        expect($route)->toBeInstanceOf(RoutingRoute::class);

        // The Testbench CLI adds its own Workbench routes to the routes it caches.
        if (str_starts_with($route->uri(), '_workbench')) {
            continue;
        }

        // Compiling the route cache names unnamed routes "generated::…".
        $name = str_starts_with((string) $route->getName(), 'generated::') ? '' : $route->getName();
        $routes[] = implode('|', $route->methods()).' '.$route->uri().' '.$name.' '.$route->getActionName()
            .' '.json_encode(RouteVersioning::metadata($route)).' '.json_encode($route->middleware());
    }

    sort($routes);

    return $routes;
}

beforeEach(function () {
    $this->versioning(RouteCacheServiceProvider::CONFIG);
});

test('versioned routes serve identical responses from cached routes', function () {
    require versionedRoutesFile();
    Route::getRoutes()->refreshNameLookups();

    $before = versionedRouteSnapshot();
    $responses = versionedResponses($this);

    remote('route:cache')->mustRun();

    try {
        $this->reloadApplication();
        $this->versioning(RouteCacheServiceProvider::CONFIG);

        expect(Route::getRoutes()->getRoutes())->toHaveCount(0);
        require $this->app->getCachedRoutesPath();

        expect(Route::getRoutes())->toBeInstanceOf(CompiledRouteCollection::class)
            ->and(versionedRouteSnapshot())->toBe($before)
            ->and(versionedResponses($this))->toBe($responses);
    } finally {
        remote('route:clear')->mustRun();
    }

    // What the requests above must have produced, with and without the cache.
    expect($responses['header v1'])->toBe([200, ['controller' => 'v1', 'widget' => 1, 'name' => 'First'], 'v1,group'])
        ->and($responses['header v2'])->toBe([200, ['controller' => 'v2', 'widget' => 1, 'email' => 'first@example.com'], 'v2-show-only,v2,group'])
        ->and($responses['header v3'])->toBe([404, null, null])
        ->and($responses['policy v2'])->toBe([403, null, 'v2-show-only,v2,group'])
        ->and($responses['missing model'])->toBe([404, null, null])
        ->and($responses['query invokable'])->toBe([200, ['controller' => 'v3'], 'group'])
        ->and($responses['path v1'][0])->toBe(200)
        ->and($responses['path conflict'])->toBe([400, null, null])
        ->and($responses['named'][1])->toBe('http://localhost/api/v2/users');
});

test('route list shows versioned routes', function () {
    require versionedRoutesFile();

    expect(Artisan::call('route:list'))->toBe(0);
    $output = Artisan::output();

    expect($output)->toContain('widgets/{widget}')
        ->and($output)->toContain('v2.users');
});

test('the configuration survives config cache', function () {
    $this->versioning(['lifecycle' => ['v1' => ['status' => 'deprecated', 'deprecated_at' => '2026-06-01']]]);

    $config = config('pharaonic.api-versioning');
    $file = (string) tempnam(sys_get_temp_dir(), 'api-versioning-config');

    try {
        file_put_contents($file, '<?php return '.var_export($config, true).';'.PHP_EOL);
        expect(require $file)->toBe($config);
    } finally {
        unlink($file);
    }
});
