<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiVersioning\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Pharaonic\Laravel\ApiVersioning\ApiVersioningServiceProvider;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Models\Widget;

abstract class TestCase extends Orchestra
{
    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            ApiVersioningServiceProvider::class,
        ];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('app.url', 'http://localhost');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        // Laravel 12 serves the local disk through storage/{path} routes in every application.
        $app['config']->set('filesystems.disks.local.serve', false);
        $app['config']->set('pharaonic.api-versioning.versions', ['v1', 'v2', 'v3']);
        $app['config']->set('pharaonic.api-versioning.resolvers', ['path', 'header', 'query']);
    }

    /**
     * Change the package configuration and forget the services built from it.
     *
     * @param  array<string, mixed>  $config
     */
    protected function versioning(array $config): void
    {
        foreach ($config as $key => $value) {
            $this->app['config']->set('pharaonic.api-versioning.'.$key, $value);
        }

        foreach ([
            \Pharaonic\Laravel\ApiVersioning\VersionRegistry::class,
            \Pharaonic\Laravel\ApiVersioning\Routing\RouteVersioning::class,
            \Pharaonic\Laravel\ApiVersioning\Support\VersionNegotiator::class,
            \Pharaonic\Laravel\ApiVersioning\VersionedResources::class,
        ] as $service) {
            $this->app->forgetInstance($service);
        }
    }

    protected function router(): Router
    {
        return $this->app->make('router');
    }

    protected function createWidgets(): void
    {
        Schema::create('widgets', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->string('slug');
            $table->string('email');
            $table->unsignedInteger('widget_id')->nullable();
        });

        Widget::query()->create(['name' => 'First', 'slug' => 'first', 'email' => 'first@example.com']);
        Widget::query()->create(['name' => 'Second', 'slug' => 'second', 'email' => 'second@example.com', 'widget_id' => 1]);
    }
}
