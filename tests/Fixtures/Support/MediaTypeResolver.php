<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Support;

use Illuminate\Http\Request;
use Pharaonic\Laravel\ApiVersioning\Contracts\VersionResolver;

/**
 * A custom resolver: Accept: application/vnd.example.v2+json.
 */
final class MediaTypeResolver implements VersionResolver
{
    public function resolve(Request $request): ?string
    {
        $accept = $request->headers->get('Accept');

        if (is_string($accept) && preg_match('#application/vnd\.example\.([^+;,\s]+)\+json#', $accept, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}
