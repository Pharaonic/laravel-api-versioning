# Maintaining This Package

This file records package-specific maintainer facts that are safe to keep in the public repository.

## Purpose

Laravel-native API versioning. Version metadata is added to Laravel's own routes through `Route` macros; a request's version is resolved from the path, a header, the query string or a custom resolver; one route per method and URI can serve a controller per version; JSON resources get `whenVersion()` and an opt-in version-to-class map.

## Supported Laravel lines

One branch per Laravel major, from `6.x` to the newest major, all carrying the same code. Each line's PHP range, `illuminate/*` constraint and tools follow the Pharaonic Laravel line table. `6.x` runs PHPUnit, because Pest cannot install on Laravel 6; later lines run Pest. `develop` follows the newest line.

Per-line differences beyond constraints:

- `JsonResource` is macroable from Laravel 9. From `9.x` the provider also registers `whenVersion()` as a macro; on every line the `InteractsWithApiVersion` trait provides it, and the docs of `6.x`–`8.x` show the trait.
- Scoped bindings (`{part:slug}`) exist from Laravel 7; their test skips on `6.x`.
- `6.x` and `7.x` reproduce `route:cache` in-process in `RouteCacheTest` (serialized routes on 6, a compiled collection on 7).

## Core library

None. Version ordering, expressions and lifecycle dates are small, package-local and tied to the configuration.

## Public API invariants

Configuration keys (`pharaonic.api-versioning.*`), publish tags, and registered Blade, Artisan, macro, and validation names are public API.

- Router macros `version`, `versions`, `sinceVersion`, `untilVersion`; Route macros `versions`, `sinceVersion`, `untilVersion`, `controllers`; the Request macro `apiVersion`; the `whenVersion` JsonResource macro (9.x on) and the `InteractsWithApiVersion` trait.
- `Version`, `VersionRegistry`, `VersionedResources` (and its `FALLBACK_*` constants), `Contracts\VersionResolver`, `Exceptions\ApiVersionException` and its error-code constants and values, `Routing\VersionedRouteRegistrar` as the return type of the router macros.
- The `api_versioning` route-action key and its shape must survive `route:cache`; the request attribute `pharaonic.api-versioning` holds the `Version`.
- Built-in resolver names `path`, `header`, `query`; lifecycle statuses `active`, `deprecated`, `sunset-announced`, `retired`.

## Package-specific constraints

- No request state outside the request: the registry, negotiator, resource map and route metadata service are configuration-only singletons, and the version lives in the request attribute.
- The handler of a route with `->controllers()` is chosen in a `RouteMatched` listener, because Laravel gathers controller middleware and reads the action for implicit binding before any route middleware runs. The listener sets the action, controller and gathered middleware on every request; `AddVersionHeaders`, kept first among the route's middleware, restores the route's own action when the request leaves it. `->controllers()` therefore needs a controller action, never a closure.
- Version groups register into a `CapturingRouteCollection` and are then added to the router's collection, because Laravel merges custom group attributes with `array_merge_recursive`, which cannot express nested constraints. Laravel leaves its group stack unbalanced when a group closure throws; the package restores its collection and state in `finally`.
- Duplicate method and URI definitions are rejected at registration and, for a plain route that replaces a versioned one, when the application has booted.
- Macros are registered through `Support\Macros`, which refuses to replace a macro or method it did not define.

## Branch notes

Fixes land on the oldest affected line and are ported to every later line and `develop`.

## Documentation

https://pharaonic.dev/packages/laravel/api-versioning

Keep this file factual and package-specific. General Pharaonic engineering policy belongs in the private engineering repository, not here.
