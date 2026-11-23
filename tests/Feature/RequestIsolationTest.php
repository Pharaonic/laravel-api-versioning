<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Pharaonic\Laravel\ApiVersioning\Routing\RouteVersioning;
use Pharaonic\Laravel\ApiVersioning\Support\VersionNegotiator;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Controllers\ProfileController;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Models\Widget;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Resources\WidgetResource;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Resources\WidgetV1Resource;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Resources\WidgetV3Resource;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Support\MediaTypeResolver;
use Pharaonic\Laravel\ApiVersioning\Version;
use Pharaonic\Laravel\ApiVersioning\VersionedResources;
use Pharaonic\Laravel\ApiVersioning\VersionRegistry;

// One application serving many requests, as a long-running worker does: the
// router, its Route objects and the package singletons are shared, only the
// request is new.

test('a request never inherits the version of a previous request or host', function () {
    foreach (['tenant-a.test', 'tenant-b.test'] as $host) {
        Route::domain($host)->group(function (): void {
            Route::get('profile', ProfileController::class)->sinceVersion('v1');
        });
    }

    $this->getJson('http://tenant-a.test/profile', ['X-API-Version' => 'v3'])->assertJsonPath('version', 'v3');
    $this->getJson('http://tenant-b.test/profile')->assertStatus(400);
    $this->getJson('http://tenant-a.test/profile')->assertStatus(400);
    $this->getJson('http://tenant-b.test/profile?version=v1')->assertJsonPath('version', 'v1');
    $this->getJson('http://tenant-a.test/profile', ['X-API-Version' => 'v2'])->assertJsonPath('version', 'v2');
});

test('a sub request resolves its own version', function () {
    Route::get('inner', ProfileController::class)->sinceVersion('v1');
    Route::get('outer', function (Request $request) {
        // Router::dispatch() does not rebind the container's request, so the
        // version is read from the sub-request object itself.
        $sub = Request::create('/inner', 'GET', ['version' => 'v1']);
        app('router')->dispatch($sub);
        $inner = $sub->apiVersion();
        $outer = $request->apiVersion();

        return ['outer' => $outer instanceof Version ? $outer->name() : null, 'inner' => $inner instanceof Version ? $inner->name() : null];
    })->sinceVersion('v1');

    $this->getJson('/outer', ['X-API-Version' => 'v3'])->assertJsonPath('outer', 'v3')->assertJsonPath('inner', 'v1');
});

test('the version lives only on the request', function () {
    Route::get('profile', ProfileController::class)->sinceVersion('v1');

    $this->getJson('/profile', ['X-API-Version' => 'v2'])->assertOk();

    $fresh = Request::create('/profile');
    expect($fresh->apiVersion())->toBeNull()
        ->and($fresh->attributes->get('pharaonic.api-versioning'))->toBeNull();
});

test('services resolved before the first request keep no request state', function () {
    $this->versioning(['resolvers' => ['header', 'query', MediaTypeResolver::class]]);

    // A worker boots once: the services exist before any request, and the
    // resource map is registered at boot, as an application does.
    $services = [];

    foreach ([VersionRegistry::class, RouteVersioning::class, VersionNegotiator::class, VersionedResources::class] as $service) {
        $services[$service] = $this->app->make($service);
    }

    $services[VersionedResources::class]->register(WidgetResource::class, ['v1' => WidgetV1Resource::class, 'v3' => WidgetV3Resource::class], 'base');
    $this->createWidgets();

    Route::middleware(SubstituteBindings::class)->get('widgets/{widget}', function (Widget $widget) {
        return app(VersionedResources::class)->make(WidgetResource::class, $widget);
    })->sinceVersion('v1');
    Route::get('profile', ProfileController::class)->sinceVersion('v1');

    $this->getJson('/widgets/1', ['X-API-Version' => 'v3'])->assertJsonPath('data.profile.name', 'First');
    $this->getJson('/widgets/1?version=v1')->assertJsonPath('data.title', 'First');
    $this->get('/widgets/1', ['Accept' => 'application/vnd.example.v2+json'])->assertJsonPath('data.name', 'First');
    $this->getJson('/profile?version=v2')->assertJsonPath('version', 'v2');
    $this->getJson('/profile')->assertStatus(400);
    $this->getJson('/widgets/1', ['X-API-Version' => 'v1'])->assertJsonPath('data.title', 'First');
    $this->get('/profile', ['Accept' => 'application/vnd.example.v3+json'])->assertJsonPath('version', 'v3');

    foreach ($services as $service => $instance) {
        expect($this->app->make($service))->toBe($instance);

        foreach ((new ReflectionObject($instance))->getProperties() as $property) {
            $property->setAccessible(true);
            expect($property->getValue($instance))->not->toBeInstanceOf(Request::class);
        }
    }
});
