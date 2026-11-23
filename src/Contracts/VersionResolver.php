<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiVersioning\Contracts;

use Illuminate\Http\Request;

/**
 * Reads the API version a request asks for.
 *
 * Return the raw value the request carries, or null when it carries none.
 * The package validates the value against the declared versions, compares
 * it with the other enabled resolvers and rejects unknown or conflicting
 * versions, so a resolver never has to.
 */
interface VersionResolver
{
    public function resolve(Request $request): ?string;
}
