<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiVersioning\Support;

use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Pharaonic\Laravel\ApiVersioning\Version;

/**
 * The resolved version lives on the request itself, so it can never outlive
 * it: not across requests of a long-running worker, not into the next test
 * request, not into a sub-request.
 *
 * @internal
 */
final class CurrentVersion
{
    public const ATTRIBUTE = 'pharaonic.api-versioning';

    public static function of(?Request $request = null): ?Version
    {
        $request ??= self::request();
        $version = $request?->attributes->get(self::ATTRIBUTE);

        return $version instanceof Version ? $version : null;
    }

    public static function set(Request $request, Version $version): void
    {
        $request->attributes->set(self::ATTRIBUTE, $version);
    }

    private static function request(): ?Request
    {
        $container = Container::getInstance();

        if (! $container->bound('request')) {
            return null;
        }

        $request = $container->make('request');

        return $request instanceof Request ? $request : null;
    }
}
