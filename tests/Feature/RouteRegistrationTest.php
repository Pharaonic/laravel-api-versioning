<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiVersioning\Tests\Feature;

use BadMethodCallException;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use LogicException;
use Pharaonic\Laravel\ApiVersioning\Middleware\AddVersionHeaders;
use Pharaonic\Laravel\ApiVersioning\Routing\RouteVersioning;
use Pharaonic\Laravel\ApiVersioning\Routing\VersionedRouteRegistrar;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Controllers\ProfileController;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Controllers\UserV1Controller;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Controllers\UserV2Controller;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Middleware\Tag;
use Pharaonic\Laravel\ApiVersioning\Tests\TestCase;

final class RouteRegistrationTest extends TestCase
{
    public function test_a_version_group_with_a_closure_adds_metadata_but_no_prefix(): void
    {
        Route::version('v1', function (): void {
            Route::get('health', ProfileController::class)->name('health');
        });

        $route = Route::getRoutes()->getByName('health');

        $this->assertInstanceOf(RoutingRoute::class, $route);
        $this->assertSame('health', $route->uri());
        $this->assertSame(['v1'], RouteVersioning::metadata($route)['only'] ?? null);
        $this->assertContains(AddVersionHeaders::class, $route->middleware());

        $this->getJson('/health', ['X-API-Version' => 'v1'])->assertOk()->assertJsonPath('version', 'v1');
        $this->getJson('/health', ['X-API-Version' => 'v2'])->assertNotFound();
        $this->getJson('/v1/health')->assertNotFound();
        $this->getJson('/api/v1/health')->assertNotFound();
    }

    public function test_a_fluent_version_group_composes_with_native_prefix_name_and_middleware(): void
    {
        $registrar = Route::version('v2');
        $this->assertInstanceOf(VersionedRouteRegistrar::class, $registrar);

        Route::prefix('services')->name('svc.')->middleware(Tag::class.':outer')->group(function (): void {
            Route::version('v2')->middleware(Tag::class.':group')->prefix('admin')->name('admin.')->group(function (): void {
                Route::prefix('nested')->name('nested.')->group(function (): void {
                    Route::get('users', [UserV2Controller::class, 'index'])->name('users');
                });
            });
        });

        $route = Route::getRoutes()->getByName('svc.admin.nested.users');

        $this->assertInstanceOf(RoutingRoute::class, $route);
        $this->assertSame('services/admin/nested/users', $route->uri());

        $this->getJson('/services/admin/nested/users', ['X-API-Version' => 'v2'])
            ->assertOk()
            ->assertJsonPath('controller', 'v2')
            ->assertHeader('X-Middleware', 'v2,group,outer');

        $this->assertSame('http://localhost/services/admin/nested/users', route('svc.admin.nested.users'));
    }

    public function test_a_version_group_keeps_the_domain_of_the_group(): void
    {
        Route::version('v1')->domain('api.example.test')->group(function (): void {
            Route::get('ping', ProfileController::class);
        });

        $this->getJson('http://api.example.test/ping', ['X-API-Version' => 'v1'])->assertOk();
        $this->getJson('http://localhost/ping', ['X-API-Version' => 'v1'])->assertNotFound();
    }

    public function test_native_route_builders_work_inside_version_groups(): void
    {
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
            $this->assertInstanceOf(RoutingRoute::class, $route, $name);
            $this->assertSame(['v1'], RouteVersioning::metadata($route)['only'] ?? null, $name);
        }

        $this->postJson('/b', [], ['X-API-Version' => 'v1'])->assertOk();
        $this->patchJson('/c', [], ['X-API-Version' => 'v1'])->assertOk();
        $this->getJson('/widgets', ['X-API-Version' => 'v1'])->assertOk()->assertJsonPath('controller', 'v1');
    }

    public function test_the_registrar_registers_a_single_route_and_refuses_resources_outside_group(): void
    {
        $route = Route::version('v1')->get('ping', ProfileController::class);

        $this->assertSame(['v1'], RouteVersioning::metadata($route)['only'] ?? null);
        $this->assertSame($route, Route::getRoutes()->match(request()->create('/ping')));
        $this->assertSame(['v1'], RouteVersioning::metadata(Route::version('v1')->match(['GET'], 'pong', ProfileController::class))['only'] ?? null);

        $this->expectException(BadMethodCallException::class);
        Route::version('v1')->apiResource('widgets', UserV1Controller::class);
    }

    public function test_the_path_prefix_template_adds_the_version_inside_existing_prefixes(): void
    {
        $this->versioning(['path.prefix' => 'api/{version}', 'path.name' => '{version}.']);

        Route::prefix('services')->group(function (): void {
            Route::version('v1', function (): void {
                Route::get('users', [UserV1Controller::class, 'index'])->name('users');
            });

            Route::version('v2')->prefix('admin')->group(function (): void {
                Route::get('users', [UserV2Controller::class, 'index'])->name('users');
            });
        });

        $this->assertSame('services/api/v1/users', Route::getRoutes()->getByName('v1.users')?->uri());
        $this->assertSame('services/admin/api/v2/users', Route::getRoutes()->getByName('v2.users')?->uri());
        $this->assertSame('http://localhost/services/api/v1/users', route('v1.users'));

        $this->getJson('/services/api/v1/users')->assertOk()->assertJsonPath('controller', 'v1');
        $this->getJson('/services/admin/api/v2/users')->assertOk()->assertJsonPath('controller', 'v2');
        $this->getJson('/services/api/v1/users', ['X-API-Version' => 'v1'])->assertOk();
        $this->getJson('/services/api/v1/users', ['X-API-Version' => 'v2'])
            ->assertStatus(400)
            ->assertJsonPath('message', 'The request names conflicting API versions.');
    }

    public function test_a_bare_version_template_and_an_invalid_template(): void
    {
        $this->versioning(['path.prefix' => '{version}']);

        Route::version('v3', function (): void {
            Route::get('users', ProfileController::class);
        });

        $this->getJson('/v3/users')->assertOk()->assertJsonPath('version', 'v3');

        $this->versioning(['path.prefix' => 'api/version']);
        $this->expectException(InvalidArgumentException::class);
        Route::version('v1', function (): void {
            Route::get('other', ProfileController::class);
        });
    }

    public function test_a_shared_route_reads_its_version_from_the_route_placeholder(): void
    {
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
        $this->assertSame('http://localhost/api/v2/profile', url()->to('api/v2/profile'));
    }

    public function test_since_and_until_are_inclusive_and_follow_the_declared_order(): void
    {
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
    }

    public function test_group_constraints_apply_to_resources_and_nested_groups_intersect(): void
    {
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
    }

    public function test_constraints_reject_unknown_duplicate_and_disjoint_versions(): void
    {
        $cases = [
            'unknown' => fn () => Route::get('a', ProfileController::class)->versions('v9'),
            'duplicate' => fn () => Route::get('b', ProfileController::class)->versions('v1', 'v1'),
            'empty' => fn () => Route::get('c', ProfileController::class)->versions([]),
            'disjoint' => fn () => Route::get('d', ProfileController::class)->sinceVersion('v3')->untilVersion('v1'),
            'disjoint list' => fn () => Route::get('e', ProfileController::class)->versions('v1')->sinceVersion('v2'),
            'unknown group' => fn () => Route::version('v9'),
            'unknown since' => fn () => Route::sinceVersion('nope'),
        ];

        foreach ($cases as $case => $register) {
            try {
                $register();
                $this->fail('No exception for '.$case);
            } catch (InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_nested_version_groups_fail_and_restore_the_route_collection(): void
    {
        $collection = Route::getRoutes();

        foreach ([null, '{version}'] as $prefix) {
            $this->versioning(['path.prefix' => $prefix]);

            try {
                Route::version('v1', function (): void {
                    Route::sinceVersion('v1')->group(function (): void {
                        Route::version('v1', function (): void {
                            Route::get('x', ProfileController::class);
                        });
                    });
                });
                $this->fail('Nested version groups were accepted.');
            } catch (LogicException $e) {
                $this->assertStringContainsString('cannot be nested', $e->getMessage());
            }

            // Laravel leaves its group stack as it was when a group closure throws;
            // the package restores the collection it swapped and its own state.
            $this->assertSame($collection, Route::getRoutes());
            $this->assertCount(0, $collection->getRoutes());
        }
    }

    public function test_the_same_method_and_uri_in_two_version_groups_fails(): void
    {
        Route::version('v1', function (): void {
            Route::get('users', [UserV1Controller::class, 'index']);
        });

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Route [GET|HEAD /users] is registered more than once');

        Route::version('v2', function (): void {
            Route::get('users', [UserV2Controller::class, 'index']);
        });
    }

    public function test_the_same_method_and_uri_twice_in_one_group_fails(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('more than once in the same API version group');

        Route::version('v1', function (): void {
            Route::get('users', [UserV1Controller::class, 'index']);
            Route::get('users', [UserV2Controller::class, 'index']);
        });
    }

    public function test_two_versioned_routes_with_the_same_method_and_uri_fail(): void
    {
        Route::get('users', [UserV1Controller::class, 'index'])->versions('v1');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('->controllers()');

        Route::get('users', [UserV2Controller::class, 'index'])->versions('v2');
    }

    public function test_a_plain_route_replacing_a_versioned_one_is_detected(): void
    {
        Route::get('users', [UserV1Controller::class, 'index'])->versions('v1');
        Route::get('users', [UserV2Controller::class, 'index']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('without version constraints');

        $this->app->make(RouteVersioning::class)->assertNotReplaced(Route::getRoutes());
    }

    public function test_a_version_group_route_colliding_with_an_existing_route_fails(): void
    {
        Route::get('users', [UserV1Controller::class, 'index']);

        $this->expectException(LogicException::class);

        Route::version('v2', function (): void {
            Route::get('users', [UserV2Controller::class, 'index']);
        });
    }

    public function test_the_same_route_name_in_two_version_groups_fails(): void
    {
        Route::version('v1')->prefix('one')->group(function (): void {
            Route::get('users', [UserV1Controller::class, 'index'])->name('users');
        });

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Route name [users] is already registered');

        Route::version('v2')->prefix('two')->group(function (): void {
            Route::get('users', [UserV2Controller::class, 'index'])->name('users');
        });
    }

    public function test_route_list_shows_versioned_routes_with_their_names(): void
    {
        $this->versioning(['path.prefix' => 'api/{version}', 'path.name' => '{version}.']);

        foreach (['v1' => UserV1Controller::class, 'v2' => UserV2Controller::class] as $version => $controller) {
            Route::version($version, function () use ($controller): void {
                Route::apiResource('widgets', $controller)->only(['index', 'show']);
            });
        }

        $this->assertSame(0, Artisan::call('route:list'));
        $output = Artisan::output();

        foreach (['v1.widgets.index', 'v1.widgets.show', 'v2.widgets.index', 'api/v2/widgets/{widget}'] as $expected) {
            $this->assertStringContainsString($expected, $output);
        }
    }
}
