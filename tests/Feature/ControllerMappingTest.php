<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Controllers\InvokableV3Controller;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Controllers\ProfileController;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Controllers\UserController;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Controllers\UserV1Controller;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Controllers\UserV2Controller;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Middleware\Tag;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Models\Widget;
use Pharaonic\Laravel\ApiVersioning\Tests\Support\ScopedController;

beforeEach(function () {
    $this->createWidgets();
    Gate::define('view-widget', function (?GenericUser $user, Widget $widget): bool {
        return $widget->id === 1;
    });
});

test('one route serves a controller per header version', function () {
    Route::get('users', [UserController::class, 'index'])->controllers([
        'v1' => [UserV1Controller::class, 'index'],
        'v2' => UserV2Controller::class.'@index',
        'v3' => InvokableV3Controller::class,
    ]);

    expect(Route::getRoutes()->getRoutes())->toHaveCount(1);

    $this->getJson('/users', ['X-API-Version' => 'v1'])->assertOk()->assertJsonPath('controller', 'v1')->assertJsonPath('greeting', 'Hello v1');
    $this->getJson('/users', ['X-API-Version' => 'v2'])->assertOk()->assertJsonPath('controller', 'v2');
    $this->getJson('/users?version=v3')->assertOk()->assertJsonPath('controller', 'v3');
});

test('a version without a mapping is not found and never falls back', function () {
    Route::get('users', [UserController::class, 'index'])->controllers([
        'v1' => [UserV1Controller::class, 'index'],
        'v2' => [UserV2Controller::class, 'index'],
    ]);

    $this->getJson('/users', ['X-API-Version' => 'v3'])->assertNotFound()->assertJsonMissing(['controller' => 'v2']);
    $this->getJson('/users', ['X-API-Version' => 'v2'])->assertOk()->assertJsonPath('controller', 'v2');
});

test('an explicit fallback uses the route own controller', function () {
    Route::get('users', [UserController::class, 'index'])->controllers(['v2' => [UserV2Controller::class, 'index']], true);

    $this->getJson('/users', ['X-API-Version' => 'v1'])->assertOk()->assertJsonPath('controller', 'default')->assertHeader('X-Middleware', 'default');
    $this->getJson('/users', ['X-API-Version' => 'v2'])->assertOk()->assertJsonPath('controller', 'v2')->assertHeader('X-Middleware', 'v2');
    $this->getJson('/users', ['X-API-Version' => 'v3'])->assertOk()->assertJsonPath('controller', 'default');
});

test('route constraints limit the mapped versions', function () {
    Route::get('users', [UserController::class, 'index'])
        ->controllers(['v2' => [UserV2Controller::class, 'index']], true)
        ->untilVersion('v2');

    $this->getJson('/users', ['X-API-Version' => 'v1'])->assertOk()->assertJsonPath('controller', 'default');
    $this->getJson('/users', ['X-API-Version' => 'v3'])->assertNotFound();
});

test('implicit binding and missing models follow the version controller', function () {
    Route::get('widgets/{widget}', [UserController::class, 'show'])->middleware(SubstituteBindings::class)->controllers([
        'v1' => [UserV1Controller::class, 'show'],
        'v2' => [UserV2Controller::class, 'show'],
    ]);

    $this->getJson('/widgets/1', ['X-API-Version' => 'v1'])->assertOk()->assertJsonPath('controller', 'v1')->assertJsonPath('name', 'First');
    $this->getJson('/widgets/1', ['X-API-Version' => 'v2'])->assertOk()->assertJsonPath('controller', 'v2')->assertJsonPath('email', 'first@example.com');
    $this->getJson('/widgets/99', ['X-API-Version' => 'v1'])->assertNotFound();
    $this->getJson('/widgets/99', ['X-API-Version' => 'v2'])->assertNotFound();
});

test('explicit bindings apply before the version controller', function () {
    Route::bind('item', function (string $value): Widget {
        return Widget::query()->where('slug', $value)->firstOrFail();
    });

    Route::get('items/{item}', [UserController::class, 'show'])->middleware(SubstituteBindings::class)->controllers([
        'v1' => [UserV1Controller::class, 'show'],
        'v2' => [UserV2Controller::class, 'show'],
    ]);

    // The parameter is named "item" but the controllers take "Widget $widget":
    // Laravel passes the bound model positionally, like any other route.
    $this->getJson('/items/first', ['X-API-Version' => 'v1'])->assertOk()->assertJsonPath('widget', 1);
    $this->getJson('/items/missing', ['X-API-Version' => 'v2'])->assertNotFound();
});

test('scoped bindings work with version controllers', function () {
    Route::get('widgets/{widget}/parts/{part:slug}', [UserController::class, 'show'])->middleware(SubstituteBindings::class)->controllers([
        'v2' => [ScopedController::class, 'part'],
    ]);

    $this->getJson('/widgets/1/parts/second', ['X-API-Version' => 'v2'])->assertOk()->assertJsonPath('part', 2);
    $this->getJson('/widgets/1/parts/none', ['X-API-Version' => 'v2'])->assertNotFound();
    // "first" exists, but it is not a part of widget 1.
    $this->getJson('/widgets/1/parts/first', ['X-API-Version' => 'v2'])->assertNotFound();
});

test('controller and group middleware follow the selected controller', function () {
    Route::middleware(Tag::class.':group')->group(function (): void {
        Route::get('widgets/{widget}', [UserController::class, 'show'])->middleware(SubstituteBindings::class)->controllers([
            'v1' => [UserV1Controller::class, 'show'],
            'v2' => [UserV2Controller::class, 'show'],
        ]);
        Route::get('widgets', [UserController::class, 'index'])->controllers([
            'v2' => [UserV2Controller::class, 'index'],
        ]);
    });

    $this->getJson('/widgets/1', ['X-API-Version' => 'v1'])->assertHeader('X-Middleware', 'v1,group');
    $this->getJson('/widgets/1', ['X-API-Version' => 'v2'])->assertHeader('X-Middleware', 'v2-show-only,v2,group');
    $this->getJson('/widgets', ['X-API-Version' => 'v2'])->assertHeader('X-Middleware', 'v2,group');
});

test('authorization is enforced inside the version controller and by middleware', function () {
    Route::get('widgets/{widget}', [UserController::class, 'show'])->middleware(SubstituteBindings::class)
        ->controllers(['v1' => [UserV1Controller::class, 'show'], 'v2' => [UserV2Controller::class, 'show']]);

    Route::get('guarded/{widget}', [UserController::class, 'show'])->middleware(SubstituteBindings::class)
        ->middleware('can:view-widget,widget')
        ->controllers(['v1' => [UserV1Controller::class, 'show']]);

    $this->getJson('/widgets/2', ['X-API-Version' => 'v2'])->assertForbidden();
    $this->getJson('/widgets/2', ['X-API-Version' => 'v1'])->assertOk();
    $this->getJson('/guarded/2', ['X-API-Version' => 'v1'])->assertForbidden();
    $this->getJson('/guarded/1', ['X-API-Version' => 'v1'])->assertOk();
});

test('repeated requests on one application never reuse another version state', function () {
    Route::get('widgets/{widget}', [UserController::class, 'show'])->middleware(SubstituteBindings::class)->controllers([
        'v1' => [UserV1Controller::class, 'show'],
        'v2' => [UserV2Controller::class, 'show'],
    ], true);

    $sequence = ['v2', 'v1', 'v2', 'v3', 'v1', 'v1', 'v2'];
    $expected = ['v2' => 'v2-show-only,v2', 'v1' => 'v1', 'v3' => 'default'];

    foreach ($sequence as $version) {
        $response = $this->getJson('/widgets/1', ['X-API-Version' => $version]);
        $response->assertOk()->assertHeader('X-Middleware', $expected[$version]);
        expect($response->json('controller'))->toBe($version === 'v3' ? 'default' : $version);
    }

    $this->getJson('/widgets/1')->assertStatus(400);
    $this->getJson('/widgets/1', ['X-API-Version' => 'v1'])->assertOk()->assertJsonPath('controller', 'v1');
});

test('route list and url generation keep the route own action', function () {
    Route::get('users', [UserController::class, 'index'])->name('users.index')->controllers([
        'v2' => [UserV2Controller::class, 'index'],
    ]);

    Route::getRoutes()->refreshNameLookups();
    $route = Route::getRoutes()->getByName('users.index');

    expect($route?->getActionName())->toBe(UserController::class.'@index')
        ->and(route('users.index'))->toBe('http://localhost/users')
        ->and(action([UserController::class, 'index']))->toBe('http://localhost/users');

    $this->getJson('/users', ['X-API-Version' => 'v2'])->assertJsonPath('controller', 'v2');

    expect($route?->getActionName())->toBe(UserController::class.'@index')
        ->and($route?->getAction('controller'))->toBe(UserController::class.'@index');
});

test('invalid controller maps fail at registration', function ($register) {
    $register();
})->with([
    'missing class' => [fn () => fn () => Route::get('a', ProfileController::class)->controllers(['v1' => 'App\\Missing@index'])],
    'missing method' => [fn () => fn () => Route::get('b', ProfileController::class)->controllers(['v1' => [UserV1Controller::class, 'nope']])],
    'not invokable' => [fn () => fn () => Route::get('c', ProfileController::class)->controllers(['v1' => UserV1Controller::class])],
    'unknown version' => [fn () => fn () => Route::get('d', ProfileController::class)->controllers(['v9' => InvokableV3Controller::class])],
    'closure' => [fn () => fn () => Route::get('e', ProfileController::class)->controllers(['v1' => function (): string {
        return 'no';
    }])],
    'empty' => [fn () => fn () => Route::get('f', ProfileController::class)->controllers([])],
    'unreachable' => [fn () => fn () => Route::get('g', ProfileController::class)->sinceVersion('v2')->controllers(['v1' => InvokableV3Controller::class])],
    'unreachable later' => [fn () => fn () => Route::get('h', ProfileController::class)->controllers(['v1' => InvokableV3Controller::class])->sinceVersion('v2')],
    'closure route' => [fn () => fn () => Route::get('i', function (): string {
        return 'closure';
    })->controllers(['v1' => InvokableV3Controller::class])],
])->throws(InvalidArgumentException::class);

test('a route maps its controllers only once', function () {
    Route::get('j', ProfileController::class)->controllers(['v1' => InvokableV3Controller::class])->controllers(['v2' => InvokableV3Controller::class]);
})->throws(LogicException::class);

test('a version group rejects maps to versions outside the group', function () {
    Route::version('v1', function (): void {
        Route::get('users', [UserController::class, 'index'])->controllers([
            'v1' => [UserV1Controller::class, 'index'],
            'v2' => [UserV2Controller::class, 'index'],
        ]);
    });
})->throws(InvalidArgumentException::class, 'maps controllers to API versions [v2]');

test('group namespaces prefix string controllers like laravel', function () {
    Route::namespace('Pharaonic\\Laravel\\ApiVersioning\\Tests\\Fixtures\\Controllers')->group(function (): void {
        Route::get('users', 'UserController@index')->controllers(['v1' => 'UserV1Controller@index', 'v3' => 'InvokableV3Controller']);
    });

    $this->getJson('/users', ['X-API-Version' => 'v1'])->assertOk()->assertJsonPath('controller', 'v1');
    $this->getJson('/users', ['X-API-Version' => 'v3'])->assertOk()->assertJsonPath('controller', 'v3');
});
