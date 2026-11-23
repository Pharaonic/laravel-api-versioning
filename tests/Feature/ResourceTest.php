<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiVersioning\Tests\Feature;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use LogicException;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Models\Widget;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Resources\WidgetResource;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Resources\WidgetV1Resource;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Resources\WidgetV3Resource;
use Pharaonic\Laravel\ApiVersioning\Tests\TestCase;
use Pharaonic\Laravel\ApiVersioning\VersionedResources;

final class ResourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createWidgets();

        Route::middleware(SubstituteBindings::class)->group(function (): void {
            Route::get('widgets/{widget}', function (Widget $widget) {
                return new WidgetResource($widget);
            })->sinceVersion('v1');

            Route::get('nested', function () {
                $parent = Widget::query()->findOrFail(1);
                $parent->setRelation('children', Widget::query()->where('widget_id', 1)->get());

                return new WidgetResource($parent);
            })->sinceVersion('v1');

            Route::get('paginated', function () {
                return WidgetResource::collection(Widget::query()->paginate(1));
            })->sinceVersion('v1');

            Route::get('mapped/{widget}', function (Widget $widget) {
                return app(VersionedResources::class)->make(WidgetResource::class, $widget);
            })->sinceVersion('v1');

            Route::get('mapped', function () {
                return app(VersionedResources::class)->collection(WidgetResource::class, Widget::query()->paginate(1));
            })->sinceVersion('v1');

            Route::get('unversioned/{widget}', function (Widget $widget) {
                return app(VersionedResources::class)->make(WidgetResource::class, $widget);
            });
        });
    }

    public function test_version_fields_are_included_or_removed_entirely(): void
    {
        $v1 = $this->getJson('/widgets/1', ['X-API-Version' => 'v1'])->assertOk();
        $v1->assertJsonPath('data.legacy_name', 'First')->assertJsonPath('data.only_v1', 'yes')->assertJsonPath('data.tier', 'basic');
        $this->assertArrayNotHasKey('email', $v1->json('data'));

        $v2 = $this->getJson('/widgets/1', ['X-API-Version' => 'v2'])->assertOk();
        $v2->assertJsonPath('data.email', 'first@example.com')->assertJsonPath('data.tier', 'basic');
        $this->assertArrayNotHasKey('legacy_name', $v2->json('data'));
        $this->assertArrayNotHasKey('only_v1', $v2->json('data'));
        $this->assertStringNotContainsString('null', (string) $v2->getContent());

        $this->getJson('/widgets/1', ['X-API-Version' => 'v3'])->assertJsonPath('data.tier', 'premium');
    }

    public function test_native_conditional_helpers_are_unchanged(): void
    {
        $response = $this->getJson('/widgets/2', ['X-API-Version' => 'v2'])->assertOk();

        $response->assertJsonPath('data.parent', 1)->assertJsonPath('data.merged', true);
        $this->assertArrayNotHasKey('children', $response->json('data'));
        $this->assertArrayNotHasKey('parent', $this->getJson('/widgets/1', ['X-API-Version' => 'v2'])->json('data'));
    }

    public function test_nested_resources_use_the_same_request_version(): void
    {
        $response = $this->getJson('/nested', ['X-API-Version' => 'v1'])->assertOk();

        $response->assertJsonPath('data.children.0.id', 2)->assertJsonPath('data.children.0.legacy_name', 'Second');
        $this->assertArrayNotHasKey('email', $response->json('data.children.0'));

        $this->getJson('/nested', ['X-API-Version' => 'v2'])->assertJsonPath('data.children.0.email', 'second@example.com');
    }

    public function test_collections_and_paginators_keep_laravel_meta_links_and_wrapping(): void
    {
        $response = $this->getJson('/paginated?page=2', ['X-API-Version' => 'v2'])->assertOk();

        $response->assertJsonPath('data.0.id', 2)
            ->assertJsonPath('data.0.email', 'second@example.com')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.total', 2);
        $this->assertArrayHasKey('links', $response->json());
    }

    public function test_excluded_closures_run_no_queries(): void
    {
        Route::get('counted', function () {
            $widget = Widget::query()->findOrFail(1);
            DB::flushQueryLog();
            DB::enableQueryLog();

            $resource = new class($widget) extends JsonResource
            {
                /**
                 * @return array<string, mixed>
                 */
                public function toArray($request): array
                {
                    return [
                        'count' => \Pharaonic\Laravel\ApiVersioning\Support\VersionExpression::when('>=v2', fn () => Widget::query()->count(), null, false),
                    ];
                }
            };

            $data = $resource->resolve();

            return ['data' => $data, 'queries' => count(DB::getQueryLog())];
        })->sinceVersion('v1');

        $this->getJson('/counted', ['X-API-Version' => 'v1'])->assertJsonPath('queries', 0)->assertJsonPath('data', []);
        $this->getJson('/counted', ['X-API-Version' => 'v2'])->assertJsonPath('queries', 1)->assertJsonPath('data.count', 2);
    }

    public function test_the_macro_works_without_the_trait_where_json_resource_is_macroable(): void
    {
        if (! in_array(\Illuminate\Support\Traits\Macroable::class, class_uses_recursive(JsonResource::class), true)) {
            $this->markTestSkipped('JsonResource is macroable from Laravel 9; use the InteractsWithApiVersion trait before.');
        }

        Route::get('macro/{widget}', function (Widget $widget) {
            return new class($widget) extends JsonResource
            {
                /**
                 * @return array<string, mixed>
                 */
                public function toArray($request): array
                {
                    return [
                        'id' => $this->resource->id,
                        'email' => $this->whenVersion('>=v2', fn () => $this->resource->email),
                        'tier' => $this->whenVersion('v1', 'one', 'other'),
                    ];
                }
            };
        })->middleware(SubstituteBindings::class)->sinceVersion('v1');

        $v1 = $this->getJson('/macro/1', ['X-API-Version' => 'v1'])->assertJsonPath('data.tier', 'one');
        $this->assertArrayNotHasKey('email', $v1->json('data'));
        $this->getJson('/macro/1', ['X-API-Version' => 'v2'])->assertJsonPath('data.email', 'first@example.com')->assertJsonPath('data.tier', 'other');
    }

    public function test_every_comparison_follows_the_declared_order(): void
    {
        $this->versioning(['versions' => ['beta', 'alpha', 'v1', 'v2', 'v3']]);

        $expressions = ['beta' => [1, 0, 0], '=alpha' => [0, 1, 0], '>=alpha' => [0, 1, 1], '>alpha' => [0, 0, 1], '<=alpha' => [1, 1, 0], '<alpha' => [1, 0, 0], ' >= alpha ' => [0, 1, 1]];

        foreach (['beta', 'alpha', 'v1'] as $index => $version) {
            Route::get('compare-'.$version, function () use ($expressions) {
                $result = [];

                foreach (array_keys($expressions) as $expression) {
                    $result[$expression] = \Pharaonic\Laravel\ApiVersioning\Support\VersionExpression::matches($expression);
                }

                return $result;
            })->versions($version);

            $json = $this->getJson('/compare-'.$version, ['X-API-Version' => $version])->assertOk()->json();

            foreach ($expressions as $expression => $expected) {
                $this->assertSame((bool) $expected[$index], $json[$expression], $expression.' for '.$version);
            }
        }
    }

    public function test_invalid_and_unknown_expressions_fail(): void
    {
        foreach (['~v2', '>= ', '!=v1', 'v1,v2', '>=v9', ''] as $expression) {
            try {
                \Pharaonic\Laravel\ApiVersioning\Support\VersionExpression::matches($expression);
                $this->fail('Accepted '.$expression);
            } catch (InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_without_a_request_version_version_fields_are_removed(): void
    {
        $data = (new WidgetResource(Widget::query()->findOrFail(1)))->resolve();

        $this->assertArrayNotHasKey('email', $data);
        $this->assertArrayNotHasKey('legacy_name', $data);
        $this->assertSame('basic', $data['tier']);
    }

    public function test_mapped_resources_select_the_exact_version_class(): void
    {
        $resources = $this->app->make(VersionedResources::class);
        $resources->register(WidgetResource::class, ['v1' => WidgetV1Resource::class, 'v3' => WidgetV3Resource::class]);

        $this->getJson('/mapped/1', ['X-API-Version' => 'v1'])->assertOk()->assertJsonPath('data.title', 'First');
        $this->getJson('/mapped/1', ['X-API-Version' => 'v3'])->assertOk()->assertJsonPath('data.profile.name', 'First');
        $this->getJson('/unversioned/1')->assertOk()->assertJsonPath('data.name', 'First');

        $this->withoutExceptionHandling();
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('its fallback is "none"');
        $this->getJson('/mapped/1', ['X-API-Version' => 'v2']);
    }

    public function test_mapped_resource_fallbacks_are_explicit(): void
    {
        $this->versioning(['resource_fallback' => 'previous']);
        $resources = $this->app->make(VersionedResources::class);
        $resources->register(WidgetResource::class, ['v1' => WidgetV1Resource::class, 'v3' => WidgetV3Resource::class]);

        $this->getJson('/mapped/1', ['X-API-Version' => 'v2'])->assertJsonPath('data.title', 'First');

        $this->assertSame(WidgetV1Resource::class, $resources->resolve(WidgetResource::class, 'v2'));
        $this->assertSame(WidgetV3Resource::class, $resources->resolve(WidgetResource::class, 'v3'));

        $resources->register(WidgetV1Resource::class, ['v2' => WidgetV3Resource::class], 'previous');
        $resources->register(WidgetV3Resource::class, ['v1' => WidgetV1Resource::class], 'base');

        $this->assertSame(WidgetV1Resource::class, $resources->resolve(WidgetV1Resource::class, 'v1'));
        $this->assertSame(WidgetV3Resource::class, $resources->resolve(WidgetV3Resource::class, 'v2'));

        // Without a version (a console command, a queued job, an unversioned route) the base class is used.
        $this->app->instance('request', \Illuminate\Http\Request::create('/'));
        $this->assertSame(WidgetResource::class, $resources->resolve(WidgetResource::class));
    }

    public function test_mapped_collections_keep_pagination(): void
    {
        $this->app->make(VersionedResources::class)->register(WidgetResource::class, ['v3' => WidgetV3Resource::class], 'base');

        $this->getJson('/mapped?page=1', ['X-API-Version' => 'v3'])
            ->assertJsonPath('data.0.profile.name', 'First')
            ->assertJsonPath('meta.last_page', 2);
        $this->getJson('/mapped?page=1', ['X-API-Version' => 'v2'])->assertJsonPath('data.0.name', 'First');
    }

    public function test_invalid_resource_mappings_fail(): void
    {
        $resources = $this->app->make(VersionedResources::class);
        $cases = [
            fn () => $resources->register(Widget::class, ['v1' => WidgetV1Resource::class]),
            fn () => $resources->register(WidgetResource::class, ['v1' => Widget::class]),
            fn () => $resources->register(WidgetResource::class, ['v9' => WidgetV1Resource::class]),
            fn () => $resources->register(WidgetResource::class, []),
            fn () => $resources->register(WidgetResource::class, ['v1' => WidgetV1Resource::class], 'newest'),
            fn () => new VersionedResources($this->app->make(\Pharaonic\Laravel\ApiVersioning\VersionRegistry::class), 'latest'),
        ];

        foreach ($cases as $index => $case) {
            try {
                $case();
                $this->fail('Case '.$index.' was accepted.');
            } catch (InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }

        $resources->register(WidgetResource::class, ['v1' => WidgetV1Resource::class]);
        $this->expectException(LogicException::class);
        $resources->register(WidgetResource::class, ['v2' => WidgetV1Resource::class]);
    }
}
