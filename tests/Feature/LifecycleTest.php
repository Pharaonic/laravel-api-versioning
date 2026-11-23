<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiVersioning\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Pharaonic\Laravel\ApiVersioning\Exceptions\ApiVersionException;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Controllers\ProfileController;
use Pharaonic\Laravel\ApiVersioning\Tests\TestCase;

final class LifecycleTest extends TestCase
{
    /**
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('pharaonic.api-versioning.versions', ['v1', 'v2', 'v3', 'v4']);
        $app['config']->set('pharaonic.api-versioning.lifecycle', [
            'v1' => ['status' => 'retired', 'sunset_at' => '2026-01-01'],
            'v2' => ['status' => 'deprecated', 'deprecated_at' => '2026-06-01', 'sunset_at' => '2027-01-01T12:30:00Z', 'link' => 'https://example.com/migrate/v3'],
            'v3' => ['status' => 'sunset-announced', 'sunset_at' => '2028-02-29', 'link' => 'https://example.com/sunset'],
            'v4' => ['status' => 'active', 'deprecated_at' => '2030-01-01'],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Route::get('profile', ProfileController::class)->sinceVersion('v1');
        Route::get('custom', function () {
            return response()->json(['ok' => true])->header('Deprecation', '@1')->header('Link', '<https://example.com/docs>; rel="help"');
        })->sinceVersion('v1');
    }

    public function test_a_deprecated_version_sends_rfc_deprecation_sunset_and_link_headers(): void
    {
        $response = $this->getJson('/profile', ['X-API-Version' => 'v2'])->assertOk();

        $response->assertHeader('Deprecation', '@'.gmmktime(0, 0, 0, 6, 1, 2026))
            ->assertHeader('Sunset', 'Fri, 01 Jan 2027 12:30:00 GMT')
            ->assertHeader('Link', '<https://example.com/migrate/v3>; rel="deprecation"')
            ->assertJsonPath('version', 'v2');
        $this->assertSame(['controller' => 'profile', 'version' => 'v2', 'parameters' => []], $response->json());
    }

    public function test_an_announced_sunset_sends_only_the_sunset_headers(): void
    {
        $this->getJson('/profile', ['X-API-Version' => 'v3'])->assertOk()
            ->assertHeaderMissing('Deprecation')
            ->assertHeader('Sunset', 'Tue, 29 Feb 2028 00:00:00 GMT')
            ->assertHeader('Link', '<https://example.com/sunset>; rel="sunset"');
    }

    public function test_an_active_version_sends_no_lifecycle_headers(): void
    {
        $this->getJson('/profile', ['X-API-Version' => 'v4'])->assertOk()
            ->assertHeaderMissing('Deprecation')
            ->assertHeaderMissing('Sunset')
            ->assertHeaderMissing('Link');
    }

    public function test_a_retired_version_is_rejected_with_its_sunset_date(): void
    {
        $this->getJson('/profile', ['X-API-Version' => 'v1'])
            ->assertStatus(410)
            ->assertHeader('Sunset', 'Thu, 01 Jan 2026 00:00:00 GMT')
            ->assertHeaderMissing('Deprecation')
            ->assertJsonPath('message', 'This API version has been retired.');

        $this->versioning(['status' => ['retired' => 404]]);
        $this->withoutExceptionHandling();

        try {
            $this->getJson('/profile', ['X-API-Version' => 'v1']);
            $this->fail('A retired version was served.');
        } catch (ApiVersionException $e) {
            $this->assertSame(ApiVersionException::RETIRED, $e->errorCode());
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    public function test_headers_the_application_sets_are_kept(): void
    {
        $response = $this->getJson('/custom', ['X-API-Version' => 'v2'])->assertOk()->assertHeader('Deprecation', '@1');

        $this->assertSame(
            ['<https://example.com/docs>; rel="help"', '<https://example.com/migrate/v3>; rel="deprecation"'],
            $response->baseResponse->headers->all('link'),
        );
        $this->assertSame(['ok' => true], $response->json());
    }
}
