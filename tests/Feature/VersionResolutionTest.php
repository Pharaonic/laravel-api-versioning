<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiVersioning\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Pharaonic\Laravel\ApiVersioning\Exceptions\ApiVersionException;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Controllers\ProfileController;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Support\MediaTypeResolver;
use Pharaonic\Laravel\ApiVersioning\Tests\TestCase;
use Pharaonic\Laravel\ApiVersioning\Version;

final class VersionResolutionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('profile', ProfileController::class)->sinceVersion('v1');
    }

    public function test_the_header_and_the_query_select_the_version_of_one_route(): void
    {
        $this->getJson('/profile', ['X-API-Version' => 'v2'])->assertOk()->assertJsonPath('version', 'v2');
        $this->getJson('/profile?version=v3')->assertOk()->assertJsonPath('version', 'v3');
        $this->getJson('/profile', ['X-API-Version' => ' v1 '])->assertOk()->assertJsonPath('version', 'v1');
        $this->getJson('/profile?version=v2', ['X-API-Version' => 'v2'])->assertOk()->assertJsonPath('version', 'v2');

        $this->assertCount(1, Route::getRoutes()->getRoutes());
    }

    public function test_a_request_without_a_version_is_rejected_unless_a_default_is_configured(): void
    {
        $this->getJson('/profile')->assertStatus(400)->assertJsonPath('message', 'An API version is required.');
        $this->getJson('/profile', ['X-API-Version' => ''])->assertStatus(400);

        $this->versioning(['default' => 'v2']);

        $this->getJson('/profile')->assertOk()->assertJsonPath('version', 'v2');
    }

    public function test_unknown_versions_are_rejected_without_echoing_them(): void
    {
        foreach (['/profile?version=v9', '/profile?version[]=v1', '/profile?version=V1'] as $uri) {
            $response = $this->getJson($uri)->assertStatus(400)->assertJsonPath('message', 'Unknown API version.');
            $this->assertStringNotContainsString('v9', (string) $response->getContent());
        }

        $this->getJson('/profile', ['X-API-Version' => '<script>'])
            ->assertStatus(400)
            ->assertJsonPath('message', 'Unknown API version.');
    }

    public function test_conflicting_sources_are_rejected(): void
    {
        $this->getJson('/profile?version=v2', ['X-API-Version' => 'v1'])
            ->assertStatus(400)
            ->assertJsonPath('message', 'The request names conflicting API versions.');
    }

    public function test_the_exception_carries_an_error_code_and_the_configured_status(): void
    {
        $this->versioning(['status' => ['unknown' => 422, 'conflict' => 409, 'missing' => 406, 'unavailable' => 404, 'retired' => 410]]);
        $this->withoutExceptionHandling();

        $cases = [
            ['/profile?version=v9', [], ApiVersionException::UNKNOWN, 422],
            ['/profile?version=v2', ['X-API-Version' => 'v1'], ApiVersionException::CONFLICT, 409],
            ['/profile', [], ApiVersionException::MISSING, 406],
        ];

        foreach ($cases as [$uri, $headers, $code, $status]) {
            try {
                $this->getJson($uri, $headers);
                $this->fail('No exception for '.$code);
            } catch (ApiVersionException $e) {
                $this->assertSame($code, $e->errorCode());
                $this->assertSame($status, $e->getStatusCode());
                $this->assertSame('X-API-Version', $e->getHeaders()['Vary'] ?? null);
            }
        }
    }

    public function test_an_invalid_status_code_fails(): void
    {
        $this->versioning(['status' => ['unknown' => 200]]);
        $this->withoutExceptionHandling();

        $this->expectException(InvalidArgumentException::class);
        $this->getJson('/profile?version=v9');
    }

    public function test_only_enabled_resolvers_are_read(): void
    {
        $this->versioning(['resolvers' => ['query'], 'default' => 'v1']);

        $this->getJson('/profile', ['X-API-Version' => 'v3'])->assertOk()->assertJsonPath('version', 'v1')->assertHeaderMissing('Vary');
        $this->getJson('/profile?version=v3')->assertOk()->assertJsonPath('version', 'v3');

        $this->versioning(['resolvers' => ['header'], 'header' => 'Api-Version']);

        $this->getJson('/profile?version=v3')->assertOk()->assertJsonPath('version', 'v1');
        $this->getJson('/profile', ['Api-Version' => 'v2'])->assertOk()->assertJsonPath('version', 'v2')->assertHeader('Vary', 'Api-Version');
    }

    public function test_a_custom_resolver_class_takes_part_in_resolution(): void
    {
        $this->versioning(['resolvers' => ['header', MediaTypeResolver::class]]);

        $this->get('/profile', ['Accept' => 'application/vnd.example.v3+json'])->assertOk()->assertJsonPath('version', 'v3');
        $this->get('/profile', ['Accept' => 'application/vnd.example.v3+json', 'X-API-Version' => 'v2'])->assertStatus(400);
        $this->get('/profile', ['Accept' => 'application/vnd.example.v7+json'])->assertStatus(400);
    }

    public function test_invalid_resolver_configuration_fails(): void
    {
        $this->withoutExceptionHandling();

        foreach ([['cookie'], [], [self::class], 'header'] as $resolvers) {
            $this->versioning(['resolvers' => $resolvers]);

            try {
                $this->getJson('/profile?version=v1');
                $this->fail('Invalid resolvers were accepted.');
            } catch (InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_header_versioned_responses_vary_on_the_header_without_dropping_existing_values(): void
    {
        Route::get('cached', function () {
            return response()->json(['ok' => true])->setVary(['Accept-Encoding', 'x-api-version']);
        })->versions('v1');

        Route::get('plain', function () {
            return response()->json(['ok' => true])->setVary('Accept-Encoding');
        })->versions('v1');

        $this->assertSame(['Accept-Encoding', 'x-api-version'], $this->get('/cached', ['X-API-Version' => 'v1'])->baseResponse->getVary());
        $this->assertSame(['Accept-Encoding', 'X-API-Version'], $this->get('/plain', ['X-API-Version' => 'v1'])->baseResponse->getVary());
        $this->assertSame(['X-API-Version'], $this->getJson('/profile?version=v1')->baseResponse->getVary());
    }

    public function test_the_request_exposes_the_resolved_version_object(): void
    {
        $seen = [];

        Route::get('inspect', function (Request $request) use (&$seen) {
            $version = $request->apiVersion();
            $seen[] = $version;

            return ['name' => $version instanceof Version ? $version->name() : null];
        })->versions('v2', 'v3');

        Route::get('plain', function (Request $request) use (&$seen) {
            $seen[] = $request->apiVersion();

            return ['ok' => true];
        });

        $this->getJson('/inspect', ['X-API-Version' => 'v2'])->assertJsonPath('name', 'v2');
        $this->getJson('/plain', ['X-API-Version' => 'v2'])->assertOk();

        $this->assertInstanceOf(Version::class, $seen[0]);
        $this->assertTrue($seen[0]->is('v2'));
        $this->assertTrue($seen[0]->atLeast('v2'));
        $this->assertTrue($seen[0]->before('v3'));
        $this->assertNull($seen[1]);
        $this->assertNull(request()->apiVersion());
    }
}
