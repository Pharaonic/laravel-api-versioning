<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Pharaonic\Laravel\ApiVersioning\Exceptions\ApiVersionException;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Controllers\ProfileController;

beforeEach(function () {
    $this->versioning([
        'versions' => ['v1', 'v2', 'v3', 'v4'],
        'lifecycle' => [
            'v1' => ['status' => 'retired', 'sunset_at' => '2026-01-01'],
            'v2' => ['status' => 'deprecated', 'deprecated_at' => '2026-06-01', 'sunset_at' => '2027-01-01T12:30:00Z', 'link' => 'https://example.com/migrate/v3'],
            'v3' => ['status' => 'sunset-announced', 'sunset_at' => '2028-02-29', 'link' => 'https://example.com/sunset'],
            'v4' => ['status' => 'active', 'deprecated_at' => '2030-01-01'],
        ],
    ]);

    Route::get('profile', ProfileController::class)->sinceVersion('v1');
    Route::get('custom', function () {
        return response()->json(['ok' => true])->header('Deprecation', '@1')->header('Link', '<https://example.com/docs>; rel="help"');
    })->sinceVersion('v1');
});

test('a deprecated version sends rfc deprecation sunset and link headers', function () {
    $response = $this->getJson('/profile', ['X-API-Version' => 'v2'])->assertOk();

    $response->assertHeader('Deprecation', '@'.gmmktime(0, 0, 0, 6, 1, 2026))
        ->assertHeader('Sunset', 'Fri, 01 Jan 2027 12:30:00 GMT')
        ->assertHeader('Link', '<https://example.com/migrate/v3>; rel="deprecation"')
        ->assertJsonPath('version', 'v2');
    expect($response->json())->toBe(['controller' => 'profile', 'version' => 'v2', 'parameters' => []]);
});

test('an announced sunset sends only the sunset headers', function () {
    $this->getJson('/profile', ['X-API-Version' => 'v3'])->assertOk()
        ->assertHeaderMissing('Deprecation')
        ->assertHeader('Sunset', 'Tue, 29 Feb 2028 00:00:00 GMT')
        ->assertHeader('Link', '<https://example.com/sunset>; rel="sunset"');
});

test('an active version sends no lifecycle headers', function () {
    $this->getJson('/profile', ['X-API-Version' => 'v4'])->assertOk()
        ->assertHeaderMissing('Deprecation')
        ->assertHeaderMissing('Sunset')
        ->assertHeaderMissing('Link');
});

test('a retired version is rejected with its sunset date', function () {
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
        expect($e->errorCode())->toBe(ApiVersionException::RETIRED)
            ->and($e->getStatusCode())->toBe(404);
    }
});

test('headers the application sets are kept', function () {
    $response = $this->getJson('/custom', ['X-API-Version' => 'v2'])->assertOk()->assertHeader('Deprecation', '@1');

    expect($response->baseResponse->headers->all('link'))->toBe(['<https://example.com/docs>; rel="help"', '<https://example.com/migrate/v3>; rel="deprecation"'])
        ->and($response->json())->toBe(['ok' => true]);
});
