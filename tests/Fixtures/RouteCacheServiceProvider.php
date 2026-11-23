<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiVersioning\Tests\Fixtures;

use Illuminate\Support\ServiceProvider;

/**
 * Gives the Testbench CLI, which runs route:cache for RouteCacheTest, the same
 * versions, templates and routes as the test. Registered in testbench.yaml only.
 */
final class RouteCacheServiceProvider extends ServiceProvider
{
    /**
     * The versioning configuration RouteCacheTest caches its routes with.
     *
     * @var array<string, mixed>
     */
    public const CONFIG = [
        'versions' => ['v1', 'v2', 'v3'],
        'resolvers' => ['path', 'header', 'query'],
        'path.prefix' => 'api/{version}',
        'path.name' => '{version}.',
    ];

    public function boot(): void
    {
        foreach (self::CONFIG as $key => $value) {
            config(['pharaonic.api-versioning.'.$key => $value]);
        }

        $this->loadRoutesFrom(__DIR__.'/routes/versioned.php');
    }
}
