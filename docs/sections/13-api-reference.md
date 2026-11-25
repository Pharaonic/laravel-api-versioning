## API Reference

### Route (static, on the router)

| Method | Description | Returns |
| --- | --- | --- |
| `Route::version(string $version, Closure\|string\|null $routes = null)` | Routes that belong to one version; with routes it registers the group, without it returns a registrar | `VersionedRouteRegistrar\|null` |
| `Route::versions(string\|array ...$versions)` | A group available in the listed versions | `VersionedRouteRegistrar` |
| `Route::sinceVersion(string $version)` | A group available from a version on | `VersionedRouteRegistrar` |
| `Route::untilVersion(string $version)` | A group available up to a version | `VersionedRouteRegistrar` |

### VersionedRouteRegistrar

| Method | Description | Returns |
| --- | --- | --- |
| `version()`, `versions()`, `sinceVersion()`, `untilVersion()` | Add constraints | `$this` |
| `middleware()`, `prefix()`, `name()`, `as()`, `domain()`, `where()`, `namespace()` | Laravel's group attributes | `$this` |
| `group(Closure\|string $routes)` | Register a closure or a route file | `void` |
| `get()`, `post()`, `put()`, `patch()`, `delete()`, `options()`, `any()`, `match()` | Register one route | `Illuminate\Routing\Route` |

### Route (on a route)

| Method | Description | Returns |
| --- | --- | --- |
| `->versions(string\|array ...$versions)` | Available in the listed versions | `Route` |
| `->sinceVersion(string $version)` | Available from a version on | `Route` |
| `->untilVersion(string $version)` | Available up to a version | `Route` |
| `->controllers(array $controllers, bool $fallback = false)` | A controller per version; with `$fallback`, unmapped versions use the route's own controller | `Route` |

### Request

| Method | Description | Returns |
| --- | --- | --- |
| `$request->apiVersion()` | The resolved version, `null` when the route is not versioned | `Version\|null` |

### Version

| Method | Description | Returns |
| --- | --- | --- |
| `name()` | The version name; also `(string) $version` | `string` |
| `is($version)` | Same version | `bool` |
| `atLeast($version)` / `atMost($version)` | Same or later / same or earlier | `bool` |
| `after($version)` / `before($version)` | Later / earlier | `bool` |

Arguments are a version name or a `Version`; unknown names throw `InvalidArgumentException`.

### VersionRegistry

Resolve it from the container.

| Method | Description | Returns |
| --- | --- | --- |
| `all()` | Declared versions, oldest first | `list<string>` |
| `has(string $name)` | Whether a version is declared | `bool` |
| `get(string $name)` | A version | `Version` |
| `default()` | The default version | `Version\|null` |
| `compare($a, $b)` | Negative, zero or positive by declared order | `int` |
| `status($version)` | `active`, `deprecated`, `sunset-announced` or `retired` | `string` |

### VersionedResources

| Method | Description | Returns |
| --- | --- | --- |
| `register(string $resource, array $versions, ?string $fallback = null)` | Map a resource to classes per version | `void` |
| `resolve(string $resource, Version\|string\|null $version = null)` | The class for a version (the request's by default) | `class-string` |
| `make(string $resource, mixed $value)` | A resource of the resolved class | `JsonResource` |
| `collection(string $resource, mixed $value)` | Laravel's collection of the resolved class | `AnonymousResourceCollection` |

### Other

| Name | Description |
| --- | --- |
| `Concerns\InteractsWithApiVersion` | Trait adding `whenVersion(string $expression, mixed $value, mixed $default = null)` to a resource |
| `Contracts\VersionResolver` | `resolve(Request $request): ?string` for custom resolvers |
| `Exceptions\ApiVersionException` | `errorCode()`, `localizedMessage()` plus the `MISSING`, `UNKNOWN`, `CONFLICT`, `UNAVAILABLE` and `RETIRED` constants |
