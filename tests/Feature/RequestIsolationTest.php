<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiVersioning\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Controllers\ProfileController;
use Pharaonic\Laravel\ApiVersioning\Tests\TestCase;
use Pharaonic\Laravel\ApiVersioning\Version;

/**
 * One application serving many requests, as a long-running worker does: the
 * router, its Route objects and the package singletons are shared, only the
 * request is new.
 */
final class RequestIsolationTest extends TestCase
{
    public function test_a_request_never_inherits_the_version_of_a_previous_request_or_host(): void
    {
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
    }

    public function test_a_sub_request_resolves_its_own_version(): void
    {
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
    }

    public function test_the_version_lives_only_on_the_request(): void
    {
        Route::get('profile', ProfileController::class)->sinceVersion('v1');

        $this->getJson('/profile', ['X-API-Version' => 'v2'])->assertOk();

        $fresh = Request::create('/profile');
        $this->assertNull($fresh->apiVersion());
        $this->assertNull($fresh->attributes->get('pharaonic.api-versioning'));
    }
}
