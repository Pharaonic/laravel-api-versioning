<?php

declare(strict_types=1);

use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Controllers\InvokableV3Controller;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Controllers\ProfileController;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Controllers\UserController;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Controllers\UserV1Controller;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Controllers\UserV2Controller;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Middleware\Tag;

// The routes RouteCacheTest caches, with the path template api/{version} and
// the name template {version}. that TestCase and RouteCacheServiceProvider set.

Route::middleware([SubstituteBindings::class, Tag::class.':group'])->group(function (): void {
    Route::get('widgets/{widget}', [UserController::class, 'show'])->controllers([
        'v1' => [UserV1Controller::class, 'show'],
        'v2' => [UserV2Controller::class, 'show'],
    ]);
    Route::get('users', [UserController::class, 'index'])->name('users')->controllers([
        'v1' => UserV1Controller::class.'@index',
        'v3' => InvokableV3Controller::class,
    ]);
});

foreach (['v1' => UserV1Controller::class, 'v2' => UserV2Controller::class] as $version => $controller) {
    Route::version($version, function () use ($controller): void {
        Route::get('users', [$controller, 'index'])->name('users');
    });
}

Route::prefix('shared/{version}')->group(function (): void {
    Route::get('profile', ProfileController::class)->versions('v2', 'v3');
});
