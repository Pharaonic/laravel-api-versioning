<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiVersioning\Resolvers;

use Illuminate\Http\Request;
use Pharaonic\Laravel\ApiVersioning\Contracts\VersionResolver;

/**
 * Reads the version from a query string parameter, `version` by default.
 */
final class QueryVersionResolver implements VersionResolver
{
    public function __construct(private string $parameter) {}

    public function resolve(Request $request): ?string
    {
        $query = $request->query->all();

        if (! array_key_exists($this->parameter, $query)) {
            return null;
        }

        $value = $query[$this->parameter];

        // ?version[]=v1 is an explicit but invalid version; "[]" is never a
        // declared version name, so it is rejected as an unknown version.
        return is_string($value) ? $value : '[]';
    }
}
