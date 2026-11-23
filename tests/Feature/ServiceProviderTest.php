<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiVersioning\Tests\Feature;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use LogicException;
use Pharaonic\Laravel\ApiVersioning\ApiVersioningServiceProvider;
use Pharaonic\Laravel\ApiVersioning\Support\Macros;
use Pharaonic\Laravel\ApiVersioning\Tests\TestCase;
use Pharaonic\Laravel\ApiVersioning\VersionedResources;
use Pharaonic\Laravel\ApiVersioning\VersionRegistry;
use ReflectionProperty;

final class ServiceProviderTest extends TestCase
{
    public function test_the_configuration_is_merged_under_pharaonic(): void
    {
        $defaults = require __DIR__.'/../../config/api-versioning.php';

        $this->assertSame(['v1', 'v2', 'v3'], config('pharaonic.api-versioning.versions'));
        $this->assertSame($defaults['status'], config('pharaonic.api-versioning.status'));
        $this->assertSame('X-API-Version', config('pharaonic.api-versioning.header'));
        $this->assertNull(config('pharaonic.api-versioning.default'));
        $this->assertSame(['v1'], $defaults['versions']);
        $this->assertSame(['path', 'header'], $defaults['resolvers']);
    }

    public function test_the_configuration_is_published_with_the_four_tags(): void
    {
        foreach (['pharaonic', 'laravel-api-versioning', 'pharaonic-config', 'api-versioning-config'] as $tag) {
            $paths = ServiceProvider::pathsToPublish(ApiVersioningServiceProvider::class, $tag);

            $this->assertSame([realpath(__DIR__.'/../../config/api-versioning.php')], array_map('realpath', array_keys($paths)), $tag);
            $this->assertSame([$this->app->configPath('pharaonic/api-versioning.php')], array_values($paths), $tag);
        }
    }

    public function test_services_are_singletons_built_from_configuration(): void
    {
        $this->assertSame($this->app->make(VersionRegistry::class), $this->app->make(VersionRegistry::class));
        $this->assertSame(['v1', 'v2', 'v3'], $this->app->make(VersionRegistry::class)->all());
        $this->assertSame($this->app->make(VersionedResources::class), $this->app->make(VersionedResources::class));
    }

    public function test_the_macros_are_registered_and_registering_again_is_harmless(): void
    {
        Macros::register();
        Macros::register();

        foreach ([Router::class => ['version', 'versions', 'sinceVersion', 'untilVersion'], Route::class => ['versions', 'sinceVersion', 'untilVersion', 'controllers'], Request::class => ['apiVersion']] as $class => $macros) {
            foreach ($macros as $macro) {
                $this->assertTrue($class::hasMacro($macro), $class.'::'.$macro);
            }
        }
    }

    public function test_a_foreign_macro_with_the_same_name_is_never_replaced(): void
    {
        $property = new ReflectionProperty(Router::class, 'macros');
        $property->setAccessible(true);
        /** @var array<string, mixed> $original */
        $original = $property->getValue();

        $foreign = function (): string {
            return 'foreign';
        };
        Router::macro('sinceVersion', $foreign);

        try {
            Macros::register();
            $this->fail('A foreign macro was replaced.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('Router::sinceVersion()', $e->getMessage());
            $macros = $property->getValue();
            $this->assertIsArray($macros);
            $this->assertSame($foreign, $macros['sinceVersion']);
        } finally {
            $property->setValue(null, $original);
        }

        $this->assertInstanceOf(Closure::class, $original['sinceVersion']);
    }
}
