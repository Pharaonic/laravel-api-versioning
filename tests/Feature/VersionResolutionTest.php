<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Pharaonic\Laravel\ApiVersioning\Exceptions\ApiVersionException;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Controllers\ProfileController;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Support\MediaTypeResolver;
use Pharaonic\Laravel\ApiVersioning\Version;

beforeEach(function () {
    Route::get('profile', ProfileController::class)->sinceVersion('v1');
});

test('the header and the query select the version of one route', function () {
    $this->getJson('/profile', ['X-API-Version' => 'v2'])->assertOk()->assertJsonPath('version', 'v2');
    $this->getJson('/profile?version=v3')->assertOk()->assertJsonPath('version', 'v3');
    $this->getJson('/profile', ['X-API-Version' => ' v1 '])->assertOk()->assertJsonPath('version', 'v1');
    $this->getJson('/profile?version=v2', ['X-API-Version' => 'v2'])->assertOk()->assertJsonPath('version', 'v2');

    expect(Route::getRoutes()->getRoutes())->toHaveCount(1);
});

test('a request without a version is rejected unless a default is configured', function () {
    $this->getJson('/profile')->assertStatus(400)->assertJsonPath('message', 'An API version is required.');
    $this->getJson('/profile', ['X-API-Version' => ''])->assertStatus(400);

    $this->versioning(['default' => 'v2']);

    $this->getJson('/profile')->assertOk()->assertJsonPath('version', 'v2');
});

test('unknown versions are rejected without echoing them', function () {
    foreach (['/profile?version=v9', '/profile?version[]=v1', '/profile?version=V1'] as $uri) {
        $response = $this->getJson($uri)->assertStatus(400)->assertJsonPath('message', 'Unknown API version.');
        expect((string) $response->getContent())->not->toContain('v9');
    }

    $this->getJson('/profile', ['X-API-Version' => '<script>'])
        ->assertStatus(400)
        ->assertJsonPath('message', 'Unknown API version.');
});

test('conflicting sources are rejected', function () {
    $this->getJson('/profile?version=v2', ['X-API-Version' => 'v1'])
        ->assertStatus(400)
        ->assertJsonPath('message', 'The request names conflicting API versions.');
});

test('the exception carries an error code and the configured status', function (string $uri, array $headers, string $code, int $status) {
    $this->versioning(['status' => ['unknown' => 422, 'conflict' => 409, 'missing' => 406, 'unavailable' => 404, 'retired' => 410]]);
    $this->withoutExceptionHandling();

    try {
        $this->getJson($uri, $headers);
        $this->fail('No exception for '.$code);
    } catch (ApiVersionException $e) {
        expect($e->errorCode())->toBe($code)
            ->and($e->getStatusCode())->toBe($status)
            ->and($e->getHeaders()['Vary'] ?? null)->toBe('X-API-Version');
    }
})->with([
    'unknown' => ['/profile?version=v9', [], ApiVersionException::UNKNOWN, 422],
    'conflict' => ['/profile?version=v2', ['X-API-Version' => 'v1'], ApiVersionException::CONFLICT, 409],
    'missing' => ['/profile', [], ApiVersionException::MISSING, 406],
]);

test('an invalid status code fails', function () {
    $this->versioning(['status' => ['unknown' => 200]]);
    $this->withoutExceptionHandling();

    $this->getJson('/profile?version=v9');
})->throws(InvalidArgumentException::class);

test('only enabled resolvers are read', function () {
    $this->versioning(['resolvers' => ['query'], 'default' => 'v1']);

    $this->getJson('/profile', ['X-API-Version' => 'v3'])->assertOk()->assertJsonPath('version', 'v1')->assertHeaderMissing('Vary');
    $this->getJson('/profile?version=v3')->assertOk()->assertJsonPath('version', 'v3');

    $this->versioning(['resolvers' => ['header'], 'header' => 'Api-Version']);

    $this->getJson('/profile?version=v3')->assertOk()->assertJsonPath('version', 'v1');
    $this->getJson('/profile', ['Api-Version' => 'v2'])->assertOk()->assertJsonPath('version', 'v2')->assertHeader('Vary', 'Api-Version');
});

test('a custom resolver class takes part in resolution', function () {
    $this->versioning(['resolvers' => ['header', MediaTypeResolver::class]]);

    $this->get('/profile', ['Accept' => 'application/vnd.example.v3+json'])->assertOk()->assertJsonPath('version', 'v3');
    $this->get('/profile', ['Accept' => 'application/vnd.example.v3+json', 'X-API-Version' => 'v2'])->assertStatus(400);
    $this->get('/profile', ['Accept' => 'application/vnd.example.v7+json'])->assertStatus(400);
});

test('invalid resolver configuration fails', function ($resolvers) {
    $this->withoutExceptionHandling();
    $this->versioning(['resolvers' => $resolvers]);

    $this->getJson('/profile?version=v1');
})->with([
    'unknown name' => [['cookie']],
    'empty' => [[]],
    'not a resolver' => [[ProfileController::class]],
    'not a list' => ['header'],
])->throws(InvalidArgumentException::class);

test('header versioned responses vary on the header without dropping existing values', function () {
    Route::get('cached', function () {
        return response()->json(['ok' => true])->setVary(['Accept-Encoding', 'x-api-version']);
    })->versions('v1');

    Route::get('plain', function () {
        return response()->json(['ok' => true])->setVary('Accept-Encoding');
    })->versions('v1');

    expect($this->get('/cached', ['X-API-Version' => 'v1'])->baseResponse->getVary())->toBe(['Accept-Encoding', 'x-api-version'])
        ->and($this->get('/plain', ['X-API-Version' => 'v1'])->baseResponse->getVary())->toBe(['Accept-Encoding', 'X-API-Version'])
        ->and($this->getJson('/profile?version=v1')->baseResponse->getVary())->toBe(['X-API-Version']);
});

test('the request exposes the resolved version object', function () {
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

    expect($seen[0])->toBeInstanceOf(Version::class)
        ->and($seen[0]->is('v2'))->toBeTrue()
        ->and($seen[0]->atLeast('v2'))->toBeTrue()
        ->and($seen[0]->before('v3'))->toBeTrue()
        ->and($seen[1])->toBeNull()
        ->and(request()->apiVersion())->toBeNull();
});
