<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiVersioning\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use LogicException;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Controllers\InvokableV3Controller;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Controllers\ProfileController;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Controllers\UserController;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Controllers\UserV1Controller;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Controllers\UserV2Controller;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Middleware\Tag;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Models\Widget;
use Pharaonic\Laravel\ApiVersioning\Tests\TestCase;

final class ControllerMappingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createWidgets();
        Gate::define('view-widget', function (?GenericUser $user, Widget $widget): bool {
            return $widget->id === 1;
        });
    }

    public function test_one_route_serves_a_controller_per_header_version(): void
    {
        Route::get('users', [UserController::class, 'index'])->controllers([
            'v1' => [UserV1Controller::class, 'index'],
            'v2' => UserV2Controller::class.'@index',
            'v3' => InvokableV3Controller::class,
        ]);

        $this->assertCount(1, Route::getRoutes()->getRoutes());

        $this->getJson('/users', ['X-API-Version' => 'v1'])->assertOk()->assertJsonPath('controller', 'v1')->assertJsonPath('greeting', 'Hello v1');
        $this->getJson('/users', ['X-API-Version' => 'v2'])->assertOk()->assertJsonPath('controller', 'v2');
        $this->getJson('/users?version=v3')->assertOk()->assertJsonPath('controller', 'v3');
    }

    public function test_a_version_without_a_mapping_is_not_found_and_never_falls_back(): void
    {
        Route::get('users', [UserController::class, 'index'])->controllers([
            'v1' => [UserV1Controller::class, 'index'],
            'v2' => [UserV2Controller::class, 'index'],
        ]);

        $this->getJson('/users', ['X-API-Version' => 'v3'])->assertNotFound()->assertJsonMissing(['controller' => 'v2']);
        $this->getJson('/users', ['X-API-Version' => 'v2'])->assertOk()->assertJsonPath('controller', 'v2');
    }

    public function test_an_explicit_fallback_uses_the_route_own_controller(): void
    {
        Route::get('users', [UserController::class, 'index'])->controllers(['v2' => [UserV2Controller::class, 'index']], true);

        $this->getJson('/users', ['X-API-Version' => 'v1'])->assertOk()->assertJsonPath('controller', 'default')->assertHeader('X-Middleware', 'default');
        $this->getJson('/users', ['X-API-Version' => 'v2'])->assertOk()->assertJsonPath('controller', 'v2')->assertHeader('X-Middleware', 'v2');
        $this->getJson('/users', ['X-API-Version' => 'v3'])->assertOk()->assertJsonPath('controller', 'default');
    }

    public function test_route_constraints_limit_the_mapped_versions(): void
    {
        Route::get('users', [UserController::class, 'index'])
            ->controllers(['v2' => [UserV2Controller::class, 'index']], true)
            ->untilVersion('v2');

        $this->getJson('/users', ['X-API-Version' => 'v1'])->assertOk()->assertJsonPath('controller', 'default');
        $this->getJson('/users', ['X-API-Version' => 'v3'])->assertNotFound();
    }

    public function test_implicit_binding_and_missing_models_follow_the_version_controller(): void
    {
        Route::get('widgets/{widget}', [UserController::class, 'show'])->middleware(SubstituteBindings::class)->controllers([
            'v1' => [UserV1Controller::class, 'show'],
            'v2' => [UserV2Controller::class, 'show'],
        ]);

        $this->getJson('/widgets/1', ['X-API-Version' => 'v1'])->assertOk()->assertJsonPath('controller', 'v1')->assertJsonPath('name', 'First');
        $this->getJson('/widgets/1', ['X-API-Version' => 'v2'])->assertOk()->assertJsonPath('controller', 'v2')->assertJsonPath('email', 'first@example.com');
        $this->getJson('/widgets/99', ['X-API-Version' => 'v1'])->assertNotFound();
        $this->getJson('/widgets/99', ['X-API-Version' => 'v2'])->assertNotFound();
    }

    public function test_explicit_bindings_apply_before_the_version_controller(): void
    {
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
    }

    public function test_scoped_bindings_work_with_version_controllers(): void
    {
        if (! method_exists(\Illuminate\Routing\Route::class, 'bindingFields')) {
            $this->markTestSkipped('Scoped bindings ({part:slug}) need Laravel 7.');
        }

        Route::get('widgets/{widget}/parts/{part:slug}', [UserController::class, 'show'])->middleware(SubstituteBindings::class)->controllers([
            'v2' => [\Pharaonic\Laravel\ApiVersioning\Tests\Support\ScopedController::class, 'part'],
        ]);

        $this->getJson('/widgets/1/parts/second', ['X-API-Version' => 'v2'])->assertOk()->assertJsonPath('part', 2);
        $this->getJson('/widgets/1/parts/none', ['X-API-Version' => 'v2'])->assertNotFound();
        // "first" exists, but it is not a part of widget 1.
        $this->getJson('/widgets/1/parts/first', ['X-API-Version' => 'v2'])->assertNotFound();
    }

    public function test_controller_and_group_middleware_follow_the_selected_controller(): void
    {
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
    }

    public function test_authorization_is_enforced_inside_the_version_controller_and_by_middleware(): void
    {
        Route::get('widgets/{widget}', [UserController::class, 'show'])->middleware(SubstituteBindings::class)
            ->controllers(['v1' => [UserV1Controller::class, 'show'], 'v2' => [UserV2Controller::class, 'show']]);

        Route::get('guarded/{widget}', [UserController::class, 'show'])->middleware(SubstituteBindings::class)
            ->middleware('can:view-widget,widget')
            ->controllers(['v1' => [UserV1Controller::class, 'show']]);

        $this->getJson('/widgets/2', ['X-API-Version' => 'v2'])->assertForbidden();
        $this->getJson('/widgets/2', ['X-API-Version' => 'v1'])->assertOk();
        $this->getJson('/guarded/2', ['X-API-Version' => 'v1'])->assertForbidden();
        $this->getJson('/guarded/1', ['X-API-Version' => 'v1'])->assertOk();
    }

    public function test_repeated_requests_on_one_application_never_reuse_another_version_state(): void
    {
        Route::get('widgets/{widget}', [UserController::class, 'show'])->middleware(SubstituteBindings::class)->controllers([
            'v1' => [UserV1Controller::class, 'show'],
            'v2' => [UserV2Controller::class, 'show'],
        ], true);

        $sequence = ['v2', 'v1', 'v2', 'v3', 'v1', 'v1', 'v2'];
        $expected = ['v2' => 'v2-show-only,v2', 'v1' => 'v1', 'v3' => 'default'];

        foreach ($sequence as $version) {
            $response = $this->getJson('/widgets/1', ['X-API-Version' => $version]);
            $response->assertOk()->assertHeader('X-Middleware', $expected[$version]);
            $this->assertSame($version === 'v3' ? 'default' : $version, $response->json('controller'));
        }

        $this->getJson('/widgets/1')->assertStatus(400);
        $this->getJson('/widgets/1', ['X-API-Version' => 'v1'])->assertOk()->assertJsonPath('controller', 'v1');
    }

    public function test_route_list_and_url_generation_keep_the_route_own_action(): void
    {
        Route::get('users', [UserController::class, 'index'])->name('users.index')->controllers([
            'v2' => [UserV2Controller::class, 'index'],
        ]);

        Route::getRoutes()->refreshNameLookups();
        $route = Route::getRoutes()->getByName('users.index');

        $this->assertSame(UserController::class.'@index', $route?->getActionName());
        $this->assertSame('http://localhost/users', route('users.index'));
        $this->assertSame('http://localhost/users', action([UserController::class, 'index']));

        $this->getJson('/users', ['X-API-Version' => 'v2'])->assertJsonPath('controller', 'v2');
        $this->assertSame(UserController::class.'@index', $route?->getActionName());
        $this->assertSame(UserController::class.'@index', $route?->getAction('controller'));
    }

    public function test_invalid_controller_maps_fail_at_registration(): void
    {
        $cases = [
            'missing class' => fn () => Route::get('a', ProfileController::class)->controllers(['v1' => 'App\\Missing@index']),
            'missing method' => fn () => Route::get('b', ProfileController::class)->controllers(['v1' => [UserV1Controller::class, 'nope']]),
            'not invokable' => fn () => Route::get('c', ProfileController::class)->controllers(['v1' => UserV1Controller::class]),
            'unknown version' => fn () => Route::get('d', ProfileController::class)->controllers(['v9' => InvokableV3Controller::class]),
            'closure' => fn () => Route::get('e', ProfileController::class)->controllers(['v1' => function (): string {
                return 'no';
            }]),
            'empty' => fn () => Route::get('f', ProfileController::class)->controllers([]),
            'unreachable' => fn () => Route::get('g', ProfileController::class)->sinceVersion('v2')->controllers(['v1' => InvokableV3Controller::class]),
            'unreachable later' => fn () => Route::get('h', ProfileController::class)->controllers(['v1' => InvokableV3Controller::class])->sinceVersion('v2'),
            'closure route' => fn () => Route::get('i', function (): string {
                return 'closure';
            })->controllers(['v1' => InvokableV3Controller::class]),
        ];

        foreach ($cases as $case => $register) {
            try {
                $register();
                $this->fail('No exception for '.$case);
            } catch (InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(LogicException::class);
        Route::get('j', ProfileController::class)->controllers(['v1' => InvokableV3Controller::class])->controllers(['v2' => InvokableV3Controller::class]);
    }

    public function test_a_version_group_rejects_maps_to_versions_outside_the_group(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('maps controllers to API versions [v2]');

        Route::version('v1', function (): void {
            Route::get('users', [UserController::class, 'index'])->controllers([
                'v1' => [UserV1Controller::class, 'index'],
                'v2' => [UserV2Controller::class, 'index'],
            ]);
        });
    }

    public function test_group_namespaces_prefix_string_controllers_like_laravel(): void
    {
        Route::namespace('Pharaonic\\Laravel\\ApiVersioning\\Tests\\Fixtures\\Controllers')->group(function (): void {
            Route::get('users', 'UserController@index')->controllers(['v1' => 'UserV1Controller@index', 'v3' => 'InvokableV3Controller']);
        });

        $this->getJson('/users', ['X-API-Version' => 'v1'])->assertOk()->assertJsonPath('controller', 'v1');
        $this->getJson('/users', ['X-API-Version' => 'v3'])->assertOk()->assertJsonPath('controller', 'v3');
    }
}
