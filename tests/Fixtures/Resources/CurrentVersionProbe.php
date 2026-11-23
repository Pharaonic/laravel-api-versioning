<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Resources;

use Illuminate\Http\Request;
use Pharaonic\Laravel\ApiVersioning\Version;

/**
 * Reads the version the package stored on the current request.
 */
final class CurrentVersionProbe
{
    public static function isV1(): bool
    {
        $request = app('request');
        $version = $request instanceof Request ? $request->attributes->get('pharaonic.api-versioning') : null;

        return $version instanceof Version && $version->is('v1');
    }
}
