<?php

declare(strict_types=1);

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Pharaonic\Laravel\ApiVersioning\Middleware\AddVersionHeaders;
use Pharaonic\Laravel\ApiVersioning\Routing\RouteVersioning;
use Pharaonic\Laravel\ApiVersioning\Routing\VersionedRouteRegistrar;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Controllers\ProfileController;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Controllers\UserV1Controller;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Controllers\UserV2Controller;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Middleware\Tag;

test('a version group with a closure adds metadata but no prefix', function () {
    Route::version('v1', function (): void {
        Route::get('health', ProfileController::class)->name('health');
    });

    $route = Route::getRoutes()->getByName('health');

    expect($route)->toBeInstanceOf(RoutingRoute::class)
        ->and($route->uri())->toBe('health')
        ->and(RouteVersioning::metadata($route)['only'] ?? null)->toBe(['v1'])
        ->and($route->middleware())->toContain(AddVersionHeaders::class);

    $this->getJson('/health', ['X-API-Version' => 'v1'])->assertOk()->assertJsonPath('version', 'v1');
    $this->getJson('/health', ['X-API-Version' => 'v2'])->assertNotFound();
    $this->getJson('/v1/health')->assertNotFound();
    $this->getJson('/api/v1/health')->assertNotFound();
});

test('a fluent version group composes with native prefix name and middleware', function () {
    expect(Route::version('v2'))->toBeInstanceOf(VersionedRouteRegistrar::class);

    Route::prefix('services')->name('svc.')->middleware(Tag::class.':outer')->group(function (): void {
        Route::version('v2')->middleware(Tag::class.':group')->prefix('admin')->name('admin.')->group(function (): void {
            Route::prefix('nested')->name('nested.')->group(function (): void {
                Route::get('users', [UserV2Controller::class, 'index'])->name('users');
            });
        });
    });

    $route = Route::getRoutes()->getByName('svc.admin.nested.users');

    expect($route)->toBeInstanceOf(RoutingRoute::class)
        ->and($route->uri())->toBe('services/admin/nested/users');

    $this->getJson('/services/admin/nested/users', ['X-API-Version' => 'v2'])
        ->assertOk()
        ->assertJsonPath('controller', 'v2')
        ->assertHeader('X-Middleware', 'v2,group,outer');

    expect(route('svc.admin.nested.users'))->toBe('http://localhost/services/admin/nested/users');
});

test('a version group keeps the domain of the group', function () {
    Route::version('v1')->domain('api.example.test')->group(function (): void {
        Route::get('ping', ProfileController::class);
    });

    $this->getJson('http://api.example.test/ping', ['X-API-Version' => 'v1'])->assertOk();
    $this->getJson('http://localhost/ping', ['X-API-Version' => 'v1'])->assertNotFound();
});

test('native route builders work inside version groups', function () {
    Route::version('v1', function (): void {
        Route::get('a', ProfileController::class)->name('a');
        Route::post('b', ProfileController::class)->name('b');
        Route::match(['PUT', 'PATCH'], 'c', ProfileController::class)->name('c');
        Route::any('d', ProfileController::class)->name('d');
        Route::apiResource('widgets', UserV1Controller::class)->only(['index', 'show']);
        Route::resource('things', UserV1Controller::class)->only(['index']);
    });

    foreach (['a', 'b', 'c', 'd', 'widgets.index', 'widgets.show', 'things.index'] as $name) {
        $route = Route::getRoutes()->getByName($name);
        expect($route)->toBeInstanceOf(RoutingRoute::class, $name)
            ->and(RouteVersioning::metadata($route)['only'] ?? null)->toBe(['v1'], $name);
    }

    $this->postJson('/b', [], ['X-API-Version' => 'v1'])->assertOk();
    $this->patchJson('/c', [], ['X-API-Version' => 'v1'])->assertOk();
    $this->getJson('/widgets', ['X-API-Version' => 'v1'])->assertOk()->assertJsonPath('controller', 'v1');
});

test('the registrar registers a single route and refuses resources outside a group', function () {
    $route = Route::version('v1')->get('ping', ProfileController::class);

    expect(RouteVersioning::metadata($route)['only'] ?? null)->toBe(['v1'])
        ->and(Route::getRoutes()->match(request()->create('/ping')))->toBe($route)
        ->and(RouteVersioning::metadata(Route::version('v1')->match(['GET'], 'pong', ProfileController::class))['only'] ?? null)->toBe(['v1'])
        ->and(fn () => Route::version('v1')->apiResource('widgets', UserV1Controller::class))->toThrow(BadMethodCallException::class);
});

test('the path prefix template adds the version inside existing prefixes', function () {
    $this->versioning(['path.prefix' => 'api/{version}', 'path.name' => '{version}.']);

    Route::prefix('services')->group(function (): void {
        Route::version('v1', function (): void {
            Route::get('users', [UserV1Controller::class, 'index'])->name('users');
        });

        Route::version('v2')->prefix('admin')->group(function (): void {
            Route::get('users', [UserV2Controller::class, 'index'])->name('users');
        });
    });

    expect(Route::getRoutes()->getByName('v1.users')?->uri())->toBe('services/api/v1/users')
        ->and(Route::getRoutes()->getByName('v2.users')?->uri())->toBe('services/admin/api/v2/users')
        ->and(route('v1.users'))->toBe('http://localhost/services/api/v1/users');

    $this->getJson('/services/api/v1/users')->assertOk()->assertJsonPath('controller', 'v1');
    $this->getJson('/services/admin/api/v2/users')->assertOk()->assertJsonPath('controller', 'v2');
    $this->getJson('/services/api/v1/users', ['X-API-Version' => 'v1'])->assertOk();
    $this->getJson('/services/api/v1/users', ['X-API-Version' => 'v2'])
        ->assertStatus(400)
        ->assertJsonPath('message', 'The request names conflicting API versions.');
});

test('a bare version template and an invalid template', function () {
    $this->versioning(['path.prefix' => '{version}']);

    Route::version('v3', function (): void {
        Route::get('users', ProfileController::class);
    });

    $this->getJson('/v3/users')->assertOk()->assertJsonPath('version', 'v3');

    $this->versioning(['path.prefix' => 'api/version']);

    expect(fn () => Route::version('v1', function (): void {
        Route::get('other', ProfileController::class);
    }))->toThrow(InvalidArgumentException::class);
});

test('a shared route reads its version from the route placeholder', function () {
    Route::bind('version', function (): void {
        throw new LogicException('The version parameter must never be bound.');
    });

    Route::prefix('api/{version}')->group(function (): void {
        Route::get('profile', ProfileController::class)->versions('v1', 'v2');
    });

    Route::prefix('{version}')->group(function (): void {
        Route::get('me', ProfileController::class)->sinceVersion('v1');
    });

    $this->getJson('/api/v2/profile')->assertOk()->assertJsonPath('version', 'v2')->assertJsonPath('parameters', []);
    $this->getJson('/api/v1/profile')->assertOk()->assertJsonPath('version', 'v1');
    $this->getJson('/api/v3/profile')->assertNotFound();
    $this->getJson('/api/v9/profile')->assertStatus(400)->assertJsonPath('message', 'Unknown API version.');
    $this->getJson('/v3/me')->assertOk()->assertJsonPath('version', 'v3');

    expect(url()->to('api/v2/profile'))->toBe('http://localhost/api/v2/profile');
});

test('since and until are inclusive and follow the declared order', function () {
    $this->versioning(['versions' => ['2024-01', 'beta', 'alpha']]);

    Route::get('reports', ProfileController::class)->sinceVersion('beta');
    Route::get('legacy', ProfileController::class)->untilVersion('beta');
    Route::get('window', ProfileController::class)->sinceVersion('beta')->untilVersion('beta');
    Route::get('listed', ProfileController::class)->versions(['2024-01', 'alpha']);

    $expected = [
        'reports' => ['2024-01' => 404, 'beta' => 200, 'alpha' => 200],
        'legacy' => ['2024-01' => 200, 'beta' => 200, 'alpha' => 404],
        'window' => ['2024-01' => 404, 'beta' => 200, 'alpha' => 404],
        'listed' => ['2024-01' => 200, 'beta' => 404, 'alpha' => 200],
    ];

    foreach ($expected as $uri => $statuses) {
        foreach ($statuses as $version => $status) {
            $this->getJson('/'.$uri, ['X-API-Version' => $version])->assertStatus($status);
        }
    }
});

test('group constraints apply to resources and nested groups intersect', function () {
    Route::sinceVersion('v2')->group(function (): void {
        Route::apiResource('widgets', UserV1Controller::class)->only(['index']);

        Route::untilVersion('v2')->group(function (): void {
            Route::get('only-v2', ProfileController::class);
        });
    });

    Route::versions('v1', 'v2')->versions('v2', 'v3')->group(function (): void {
        Route::get('both', ProfileController::class)->untilVersion('v3');
    });

    $this->getJson('/widgets', ['X-API-Version' => 'v1'])->assertNotFound();
    $this->getJson('/widgets', ['X-API-Version' => 'v3'])->assertOk();
    $this->getJson('/only-v2', ['X-API-Version' => 'v2'])->assertOk();
    $this->getJson('/only-v2', ['X-API-Version' => 'v3'])->assertNotFound();
    $this->getJson('/both', ['X-API-Version' => 'v2'])->assertOk();
    $this->getJson('/both', ['X-API-Version' => 'v1'])->assertNotFound();
    $this->getJson('/both', ['X-API-Version' => 'v3'])->assertNotFound();
});

test('constraints reject unknown duplicate and disjoint versions', function (Closure $register) {
    $register();
})->with([
    'unknown' => [fn () => fn () => Route::get('a', ProfileController::class)->versions('v9')],
    'duplicate' => [fn () => fn () => Route::get('b', ProfileController::class)->versions('v1', 'v1')],
    'empty' => [fn () => fn () => Route::get('c', ProfileController::class)->versions([])],
    'disjoint' => [fn () => fn () => Route::get('d', ProfileController::class)->sinceVersion('v3')->untilVersion('v1')],
    'disjoint list' => [fn () => fn () => Route::get('e', ProfileController::class)->versions('v1')->sinceVersion('v2')],
    'unknown group' => [fn () => fn () => Route::version('v9')],
    'unknown since' => [fn () => fn () => Route::sinceVersion('nope')],
])->throws(InvalidArgumentException::class);

test('nested version groups fail and restore the route collection', function (?string $prefix) {
    $collection = Route::getRoutes();
    $this->versioning(['path.prefix' => $prefix]);

    expect(fn () => Route::version('v1', function (): void {
        Route::sinceVersion('v1')->group(function (): void {
            Route::version('v1', function (): void {
                Route::get('x', ProfileController::class);
            });
        });
    }))->toThrow(LogicException::class, 'cannot be nested');

    // Laravel leaves its group stack as it was when a group closure throws;
    // the package restores the collection it swapped and its own state.
    expect(Route::getRoutes())->toBe($collection)
        ->and($collection->getRoutes())->toHaveCount(0);
})->with(['no prefix' => [null], 'path prefix' => ['{version}']]);

test('the same method and uri in two version groups fails', function () {
    Route::version('v1', function (): void {
        Route::get('users', [UserV1Controller::class, 'index']);
    });

    Route::version('v2', function (): void {
        Route::get('users', [UserV2Controller::class, 'index']);
    });
})->throws(LogicException::class, 'Route [GET|HEAD /users] is registered more than once');

test('the same method and uri twice in one group fails', function () {
    Route::version('v1', function (): void {
        Route::get('users', [UserV1Controller::class, 'index']);
        Route::get('users', [UserV2Controller::class, 'index']);
    });
})->throws(LogicException::class, 'more than once in the same API version group');

test('two versioned routes with the same method and uri fail', function () {
    Route::get('users', [UserV1Controller::class, 'index'])->versions('v1');
    Route::get('users', [UserV2Controller::class, 'index'])->versions('v2');
})->throws(LogicException::class, '->controllers()');

test('a plain route replacing a versioned one is detected', function () {
    Route::get('users', [UserV1Controller::class, 'index'])->versions('v1');
    Route::get('users', [UserV2Controller::class, 'index']);

    $this->app->make(RouteVersioning::class)->assertNotReplaced(Route::getRoutes());
})->throws(LogicException::class, 'without version constraints');

test('a version group route colliding with an existing route fails', function () {
    Route::get('users', [UserV1Controller::class, 'index']);

    Route::version('v2', function (): void {
        Route::get('users', [UserV2Controller::class, 'index']);
    });
})->throws(LogicException::class);

test('the same route name in two version groups fails', function () {
    Route::version('v1')->prefix('one')->group(function (): void {
        Route::get('users', [UserV1Controller::class, 'index'])->name('users');
    });

    Route::version('v2')->prefix('two')->group(function (): void {
        Route::get('users', [UserV2Controller::class, 'index'])->name('users');
    });
})->throws(LogicException::class, 'Route name [users] is already registered');

test('route list shows versioned routes with their names', function () {
    $this->versioning(['path.prefix' => 'api/{version}', 'path.name' => '{version}.']);

    foreach (['v1' => UserV1Controller::class, 'v2' => UserV2Controller::class] as $version => $controller) {
        Route::version($version, function () use ($controller): void {
            Route::apiResource('widgets', $controller)->only(['index', 'show']);
        });
    }

    expect(Artisan::call('route:list'))->toBe(0);
    $output = Artisan::output();

    foreach (['v1.widgets.index', 'v1.widgets.show', 'v2.widgets.index', 'api/v2/widgets/{widget}'] as $expected) {
        expect($output)->toContain($expected);
    }
});
