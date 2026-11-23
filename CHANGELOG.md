# Changelog

All notable changes to this project are documented here.

## 11.0.0 - Unreleased

### Compatibility

- Laravel 11 on PHP 8.2, 8.3 and 8.4.
- The same API and behavior as 10.0.0, including the Laravel Boost resources.

## 10.0.0 - Unreleased

### Added

- Laravel Boost resources: a guideline and the `api-versioning-development` skill, so coding agents in applications that use Boost learn the package.

### Compatibility

- Laravel 10 on PHP 8.1, 8.2 and 8.3.
- The same API and behavior as 9.0.0.

## 9.0.0 - Unreleased

### Compatibility

- Laravel 9 on PHP 8.0, 8.1 and 8.2.
- The same API and behavior as 8.0.0.
- `whenVersion()` is also a `JsonResource` macro, so resources no longer need the `InteractsWithApiVersion` trait; resources that use it keep working.

## 8.0.0 - Unreleased

### Compatibility

- Laravel 8.75+ on PHP 8.0 and 8.1.
- The same API and behavior as 7.0.0.
- Versioned routes and version controllers work with `php artisan route:cache`.

## 7.0.0 - Unreleased

### Compatibility

- Laravel 7.30+ on PHP 8.0.
- The same API and behavior as 6.0.0.
- Version-specific controllers work with Laravel 7's scoped bindings (`{part:slug}`) and its compiled route cache.

## 6.0.0 - Unreleased

### Added

- `Route::version()`, `Route::versions()`, `Route::sinceVersion()` and `Route::untilVersion()` group registrars that keep Laravel's own prefix, name, middleware, domain and where attributes, and accept a closure or a route file.
- `->versions()`, `->sinceVersion()`, `->untilVersion()` and `->controllers()` on individual routes. Constraints are inclusive, compared by the order of the `versions` list, and always narrow when nested.
- One Laravel route per method and URI serves a controller per version (`->controllers()`), selected before Laravel gathers middleware, so controller middleware, constructor and method injection, implicit and explicit binding and authorization run natively. Unmapped versions are not found unless the route explicitly falls back to its own controller.
- Header, query and path resolvers, custom resolvers through the `VersionResolver` contract, an optional default version, and `ApiVersionException` for missing, unknown, conflicting, unavailable and retired versions, with configurable status codes.
- Optional path templates (`path.prefix`, `path.name`) for `Route::version()` groups; no URL prefix is added by default.
- `Request::apiVersion()` and the immutable `Version` (`name()`, `is()`, `atLeast()`, `atMost()`, `after()`, `before()`), kept on the request only.
- `whenVersion()` for JSON resources through the `InteractsWithApiVersion` trait, removing fields with Laravel's `MissingValue` and never calling the closures of removed fields. From Laravel 9 it is also registered as a `JsonResource` macro.
- `VersionedResources`: opt-in mapping of a resource to version-specific resource classes, with the `none`, `base` and `previous` fallbacks.
- `Deprecation` (RFC 9745), `Sunset` (RFC 8594) and `Link` headers per version lifecycle (`active`, `deprecated`, `sunset-announced`, `retired`), and `Vary` on the version header.
- Registration errors for unknown versions, empty constraints, invalid controller maps, duplicate method and URI definitions, duplicate route names and macro name collisions.
- PHPUnit tests, Pint, PHPStan (max), CI, and the Pharaonic repository baseline.

### Compatibility

- PHP 8.0.
- Laravel 6.20 or later.
