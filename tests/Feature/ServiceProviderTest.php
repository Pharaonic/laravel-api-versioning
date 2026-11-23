<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Pharaonic\Laravel\ApiVersioning\ApiVersioningServiceProvider;
use Pharaonic\Laravel\ApiVersioning\Support\Macros;
use Pharaonic\Laravel\ApiVersioning\VersionedResources;
use Pharaonic\Laravel\ApiVersioning\VersionRegistry;

test('the configuration is merged under pharaonic', function () {
    $defaults = require __DIR__.'/../../config/api-versioning.php';

    expect(config('pharaonic.api-versioning.versions'))->toBe(['v1', 'v2', 'v3'])
        ->and(config('pharaonic.api-versioning.status'))->toBe($defaults['status'])
        ->and(config('pharaonic.api-versioning.header'))->toBe('X-API-Version')
        ->and(config('pharaonic.api-versioning.default'))->toBeNull()
        ->and($defaults['versions'])->toBe(['v1'])
        ->and($defaults['resolvers'])->toBe(['path', 'header']);
});

test('the configuration is published with the four tags', function (string $tag) {
    $paths = ServiceProvider::pathsToPublish(ApiVersioningServiceProvider::class, $tag);

    expect(array_map('realpath', array_keys($paths)))->toBe([realpath(__DIR__.'/../../config/api-versioning.php')])
        ->and(array_values($paths))->toBe([$this->app->configPath('pharaonic/api-versioning.php')]);
})->with(['pharaonic', 'laravel-api-versioning', 'pharaonic-config', 'api-versioning-config']);

test('services are singletons built from configuration', function () {
    expect($this->app->make(VersionRegistry::class))->toBe($this->app->make(VersionRegistry::class))
        ->and($this->app->make(VersionRegistry::class)->all())->toBe(['v1', 'v2', 'v3'])
        ->and($this->app->make(VersionedResources::class))->toBe($this->app->make(VersionedResources::class));
});

test('the macros are registered and registering again is harmless', function () {
    Macros::register();
    Macros::register();

    foreach ([Router::class => ['version', 'versions', 'sinceVersion', 'untilVersion'], Route::class => ['versions', 'sinceVersion', 'untilVersion', 'controllers'], Request::class => ['apiVersion'], JsonResource::class => ['whenVersion']] as $class => $macros) {
        foreach ($macros as $macro) {
            expect($class::hasMacro($macro))->toBeTrue($class.'::'.$macro);
        }
    }
});

test('a foreign macro with the same name is never replaced', function () {
    $property = new ReflectionProperty(Router::class, 'macros');
    $property->setAccessible(true);
    /** @var array<string, mixed> $original */
    $original = $property->getValue();

    $foreign = function (): string {
        return 'foreign';
    };
    Router::macro('sinceVersion', $foreign);

    try {
        expect(fn () => Macros::register())->toThrow(LogicException::class, 'Router::sinceVersion()');

        $macros = $property->getValue();
        expect($macros)->toBeArray()
            ->and($macros['sinceVersion'])->toBe($foreign);
    } finally {
        $property->setValue(null, $original);
    }

    expect($original['sinceVersion'])->toBeInstanceOf(Closure::class);
});
