<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiVersioning\Resolvers;

use Illuminate\Http\Request;
use Pharaonic\Laravel\ApiVersioning\Contracts\VersionResolver;

/**
 * Reads the version from a request header, `X-API-Version` by default.
 */
final class HeaderVersionResolver implements VersionResolver
{
    public function __construct(private string $header) {}

    public function header(): string
    {
        return $this->header;
    }

    public function resolve(Request $request): ?string
    {
        $value = $request->headers->get($this->header);

        return is_string($value) ? $value : null;
    }
}
