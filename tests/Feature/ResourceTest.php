<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Pharaonic\Laravel\ApiVersioning\Support\VersionExpression;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Models\Widget;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Resources\WidgetResource;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Resources\WidgetV1Resource;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Resources\WidgetV3Resource;
use Pharaonic\Laravel\ApiVersioning\VersionedResources;
use Pharaonic\Laravel\ApiVersioning\VersionRegistry;

beforeEach(function () {
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
});

test('version fields are included or removed entirely', function () {
    $v1 = $this->getJson('/widgets/1', ['X-API-Version' => 'v1'])->assertOk();
    $v1->assertJsonPath('data.legacy_name', 'First')->assertJsonPath('data.only_v1', 'yes')->assertJsonPath('data.tier', 'basic');
    expect($v1->json('data'))->not->toHaveKey('email');

    $v2 = $this->getJson('/widgets/1', ['X-API-Version' => 'v2'])->assertOk();
    $v2->assertJsonPath('data.email', 'first@example.com')->assertJsonPath('data.tier', 'basic');
    expect($v2->json('data'))->not->toHaveKey('legacy_name')
        ->and($v2->json('data'))->not->toHaveKey('only_v1')
        ->and((string) $v2->getContent())->not->toContain('null');

    $this->getJson('/widgets/1', ['X-API-Version' => 'v3'])->assertJsonPath('data.tier', 'premium');
});

test('native conditional helpers are unchanged', function () {
    $response = $this->getJson('/widgets/2', ['X-API-Version' => 'v2'])->assertOk();

    $response->assertJsonPath('data.parent', 1)->assertJsonPath('data.merged', true);
    expect($response->json('data'))->not->toHaveKey('children')
        ->and($this->getJson('/widgets/1', ['X-API-Version' => 'v2'])->json('data'))->not->toHaveKey('parent');
});

test('nested resources use the same request version', function () {
    $response = $this->getJson('/nested', ['X-API-Version' => 'v1'])->assertOk();

    $response->assertJsonPath('data.children.0.id', 2)->assertJsonPath('data.children.0.legacy_name', 'Second');
    expect($response->json('data.children.0'))->not->toHaveKey('email');

    $this->getJson('/nested', ['X-API-Version' => 'v2'])->assertJsonPath('data.children.0.email', 'second@example.com');
});

test('collections and paginators keep laravel meta links and wrapping', function () {
    $response = $this->getJson('/paginated?page=2', ['X-API-Version' => 'v2'])->assertOk();

    $response->assertJsonPath('data.0.id', 2)
        ->assertJsonPath('data.0.email', 'second@example.com')
        ->assertJsonPath('meta.current_page', 2)
        ->assertJsonPath('meta.total', 2);
    expect($response->json())->toHaveKey('links');
});

test('excluded closures run no queries', function () {
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
                    'count' => VersionExpression::when('>=v2', fn () => Widget::query()->count(), null, false),
                ];
            }
        };

        $data = $resource->resolve();

        return ['data' => $data, 'queries' => count(DB::getQueryLog())];
    })->sinceVersion('v1');

    $this->getJson('/counted', ['X-API-Version' => 'v1'])->assertJsonPath('queries', 0)->assertJsonPath('data', []);
    $this->getJson('/counted', ['X-API-Version' => 'v2'])->assertJsonPath('queries', 1)->assertJsonPath('data.count', 2);
});

test('the macro works without the trait', function () {
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
    expect($v1->json('data'))->not->toHaveKey('email');
    $this->getJson('/macro/1', ['X-API-Version' => 'v2'])->assertJsonPath('data.email', 'first@example.com')->assertJsonPath('data.tier', 'other');
});

test('every comparison follows the declared order', function () {
    $this->versioning(['versions' => ['beta', 'alpha', 'v1', 'v2', 'v3']]);

    $expressions = ['beta' => [1, 0, 0], '=alpha' => [0, 1, 0], '>=alpha' => [0, 1, 1], '>alpha' => [0, 0, 1], '<=alpha' => [1, 1, 0], '<alpha' => [1, 0, 0], ' >= alpha ' => [0, 1, 1]];

    foreach (['beta', 'alpha', 'v1'] as $index => $version) {
        Route::get('compare-'.$version, function () use ($expressions) {
            $result = [];

            foreach (array_keys($expressions) as $expression) {
                $result[$expression] = VersionExpression::matches($expression);
            }

            return $result;
        })->versions($version);

        $json = $this->getJson('/compare-'.$version, ['X-API-Version' => $version])->assertOk()->json();

        foreach ($expressions as $expression => $expected) {
            expect($json[$expression])->toBe((bool) $expected[$index], $expression.' for '.$version);
        }
    }
});

test('invalid and unknown expressions fail', function (string $expression) {
    VersionExpression::matches($expression);
})->with(['~v2', '>= ', '!=v1', 'v1,v2', '>=v9', ''])->throws(InvalidArgumentException::class);

test('without a request version version fields are removed', function () {
    $data = (new WidgetResource(Widget::query()->findOrFail(1)))->resolve();

    expect($data)->not->toHaveKey('email')
        ->and($data)->not->toHaveKey('legacy_name')
        ->and($data['tier'])->toBe('basic');
});

test('mapped resources select the exact version class', function () {
    $resources = $this->app->make(VersionedResources::class);
    $resources->register(WidgetResource::class, ['v1' => WidgetV1Resource::class, 'v3' => WidgetV3Resource::class]);

    $this->getJson('/mapped/1', ['X-API-Version' => 'v1'])->assertOk()->assertJsonPath('data.title', 'First');
    $this->getJson('/mapped/1', ['X-API-Version' => 'v3'])->assertOk()->assertJsonPath('data.profile.name', 'First');
    $this->getJson('/unversioned/1')->assertOk()->assertJsonPath('data.name', 'First');

    $this->withoutExceptionHandling();
    $this->getJson('/mapped/1', ['X-API-Version' => 'v2']);
})->throws(LogicException::class, 'its fallback is "none"');

test('mapped resource fallbacks are explicit', function () {
    $this->versioning(['resource_fallback' => 'previous']);
    $resources = $this->app->make(VersionedResources::class);
    $resources->register(WidgetResource::class, ['v1' => WidgetV1Resource::class, 'v3' => WidgetV3Resource::class]);

    $this->getJson('/mapped/1', ['X-API-Version' => 'v2'])->assertJsonPath('data.title', 'First');

    expect($resources->resolve(WidgetResource::class, 'v2'))->toBe(WidgetV1Resource::class)
        ->and($resources->resolve(WidgetResource::class, 'v3'))->toBe(WidgetV3Resource::class);

    $resources->register(WidgetV1Resource::class, ['v2' => WidgetV3Resource::class], 'previous');
    $resources->register(WidgetV3Resource::class, ['v1' => WidgetV1Resource::class], 'base');

    expect($resources->resolve(WidgetV1Resource::class, 'v1'))->toBe(WidgetV1Resource::class)
        ->and($resources->resolve(WidgetV3Resource::class, 'v2'))->toBe(WidgetV3Resource::class);

    // Without a version (a console command, a queued job, an unversioned route) the base class is used.
    $this->app->instance('request', Request::create('/'));
    expect($resources->resolve(WidgetResource::class))->toBe(WidgetResource::class);
});

test('mapped collections keep pagination', function () {
    $this->app->make(VersionedResources::class)->register(WidgetResource::class, ['v3' => WidgetV3Resource::class], 'base');

    $this->getJson('/mapped?page=1', ['X-API-Version' => 'v3'])
        ->assertJsonPath('data.0.profile.name', 'First')
        ->assertJsonPath('meta.last_page', 2);
    $this->getJson('/mapped?page=1', ['X-API-Version' => 'v2'])->assertJsonPath('data.0.name', 'First');
});

test('invalid resource mappings fail', function () {
    $resources = $this->app->make(VersionedResources::class);
    $cases = [
        'not a resource' => fn () => $resources->register(Widget::class, ['v1' => WidgetV1Resource::class]),
        'target not a resource' => fn () => $resources->register(WidgetResource::class, ['v1' => Widget::class]),
        'unknown version' => fn () => $resources->register(WidgetResource::class, ['v9' => WidgetV1Resource::class]),
        'empty' => fn () => $resources->register(WidgetResource::class, []),
        'unknown fallback' => fn () => $resources->register(WidgetResource::class, ['v1' => WidgetV1Resource::class], 'newest'),
        'unknown default fallback' => fn () => new VersionedResources($this->app->make(VersionRegistry::class), 'latest'),
    ];

    foreach ($cases as $register) {
        expect($register)->toThrow(InvalidArgumentException::class);
    }

    $resources->register(WidgetResource::class, ['v1' => WidgetV1Resource::class]);

    expect(fn () => $resources->register(WidgetResource::class, ['v2' => WidgetV1Resource::class]))->toThrow(LogicException::class);
});
