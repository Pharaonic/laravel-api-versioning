<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Controllers\ProfileController;
use Pharaonic\Laravel\ApiVersioning\Version;

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
