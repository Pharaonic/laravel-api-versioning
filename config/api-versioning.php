<?php

declare(strict_types=1);

return [

    /*
    | The supported API versions, oldest first. Comparisons (sinceVersion(),
    | untilVersion(), whenVersion('>=v2'), Version::atLeast()) follow this
    | order, never the names. Names use letters, digits, ".", "_" and "-".
    */
    'versions' => ['v1'],

    /*
    | The version of a request that names none, or null to reject it.
    */
    'default' => null,

    /*
    | Where a request names its version: "path", "header", "query" or a class
    | implementing Pharaonic\Laravel\ApiVersioning\Contracts\VersionResolver.
    | When several name different versions, the request is rejected.
    */
    'resolvers' => ['path', 'header'],

    'header' => 'X-API-Version',

    'query' => 'version',

    'path' => [
        // Added inside the prefixes in effect by Route::version(), e.g. "api/{version}" or "{version}".
        'prefix' => null,
        // Added inside the route names in effect by Route::version(), e.g. "{version}.".
        'name' => null,
        // The route parameter a shared route reads its version from, e.g. Route::prefix('{version}').
        'parameter' => 'version',
    ],

    /*
    | HTTP status codes of rejected requests.
    */
    'status' => [
        'missing' => 400,
        'unknown' => 400,
        'conflict' => 400,
        'unavailable' => 404,
        'retired' => 410,
    ],

    /*
    | What VersionedResources does for a version without its own resource
    | class: "none" (throw), "base" (the registered class) or "previous"
    | (the closest earlier mapped version, else the registered class).
    */
    'resource_fallback' => 'none',

    /*
    | Lifecycle per version: status "active", "deprecated" (needs
    | deprecated_at), "sunset-announced" (needs sunset_at) or "retired";
    | optional deprecated_at, sunset_at (dates) and link (absolute URL).
    |
    | 'v1' => ['status' => 'deprecated', 'deprecated_at' => '2026-06-01', 'sunset_at' => '2027-01-01'],
    */
    'lifecycle' => [],

];
