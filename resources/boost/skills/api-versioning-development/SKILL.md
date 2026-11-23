---
name: api-versioning-development
description: Versioning Laravel API routes, controllers and JSON resources with pharaonic/laravel-api-versioning (version groups, route constraints, header/query/path resolution, controllers per version, whenVersion(), versioned resource classes, lifecycle headers and errors). Use when adding, changing, retiring or testing a versioned API endpoint.
---

# Laravel API Versioning Development

## When to use this skill

Use this skill when you add or change an API endpoint in a versioned API, introduce or retire a version, change a field or a controller for one version, or write tests for versioned routes.

## Setup

The package is auto-discovered. Publish the configuration to `config/pharaonic/api-versioning.php` and declare the versions, oldest first:

```bash
php artisan vendor:publish --tag=api-versioning-config
```

```php
'versions' => ['v1', 'v2', 'v3'],    // comparisons follow this order
'default' => null,                   // a version for requests that name none, or null to reject them
'resolvers' => ['path', 'header'],   // "path", "header", "query" or a VersionResolver class
'header' => 'X-API-Version',
'query' => 'version',
'path' => ['prefix' => null, 'name' => null, 'parameter' => 'version'],
'status' => ['missing' => 400, 'unknown' => 400, 'conflict' => 400, 'unavailable' => 404, 'retired' => 410],
'resource_fallback' => 'none',      // "none", "base" or "previous"
'lifecycle' => [],
```

Version names use letters, digits, `.`, `_` and `-`. After changing `versions`, rebuild both `config:cache` and `route:cache`: routes are validated against `versions` when they load.

## Features

### Version groups

`Route::version('v1', $routes)` registers routes that belong to one version (a closure or a route file). Without routes it returns a `VersionedRouteRegistrar` that takes Laravel's group attributes (`middleware()`, `prefix()`, `name()`, `as()`, `domain()`, `where()`, `namespace()`), then `->group($routes)` or one route (`get()`, `post()`, `put()`, `patch()`, `delete()`, `options()`, `any()`, `match()`).

```php
Route::version('v2')->middleware('auth:sanctum')->prefix('admin')->group(function () {
    Route::get('users', [UserV2Controller::class, 'index'])->name('users');
});
```

`Route::versions('v1', 'v2')`, `Route::sinceVersion('v2')` and `Route::untilVersion('v2')` return the same registrar and constrain a group without adding a prefix. Use them for `Route::apiResource()` and `Route::resource()`. Constraints are inclusive, combine (`sinceVersion('v2')->untilVersion('v3')`), and nested groups always narrow. `Route::version()` groups cannot be nested.

With `path.prefix` (`'api/{version}'` or `'{version}'`) and `path.name` (`'{version}.'`), `Route::version()` groups get the version in their URL and route names, inside the prefixes and names already in effect: `/api/v1/users` named `v1.users`.

### Route constraints

```php
Route::get('profile', [ProfileController::class, 'show'])->versions('v1', 'v2');
Route::get('reports', [ReportController::class, 'index'])->sinceVersion('v2');
Route::get('legacy', [LegacyController::class, 'index'])->untilVersion('v2');
```

A shared route can read its version from its own URL with the `path.parameter` placeholder: `Route::prefix('api/{version}')->group(fn () => Route::get('profile', ...)->versions('v1', 'v2'))`. The `{version}` parameter is removed before the controller is called.

### A controller per version

```php
Route::get('users/{user}', [UserController::class, 'show'])->controllers([
    'v1' => [UserV1Controller::class, 'show'],
    'v2' => 'App\Http\Controllers\UserV2Controller@show',
    'v3' => ShowUserV3Controller::class,       // invokable
]);

Route::get('users', [UserController::class, 'index'])
    ->controllers(['v3' => [UserV3Controller::class, 'index']], true); // v1 and v2 use UserController@index
```

One Laravel route serves every version. The version's controller gets Laravel's whole pipeline: constructor injection, `$this->middleware()`, implicit, explicit and scoped bindings, method injection, `authorize()`, policies and `can`. A version without a mapping is not found (`status.unavailable`) unless the second argument is `true`. The route keeps its name and its own action, so `route()`, `action()` and `route:list` show the definition you wrote. The route's own action must be a controller, never a closure, and `->controllers()` can be called once per route.

### Reading the version

```php
$version = $request->apiVersion(); // Pharaonic\Laravel\ApiVersioning\Version, or null on routes that are not versioned

$version->name();          // "v2", also (string) $version
$version->is('v2');
$version->atLeast('v2');   // also atMost(), after(), before()
```

Arguments are names or `Version` objects; unknown names throw `InvalidArgumentException`. `Pharaonic\Laravel\ApiVersioning\VersionRegistry` (from the container) has `all()`, `has()`, `get()`, `default()`, `compare()` and `status()`.

### Resolvers

All enabled resolvers are read on every versioned request. An unknown value, an array (`?version[]=v1`) or two sources naming different versions are rejected; empty values count as absent; without a value, `default` is used or the request is rejected. With the `header` resolver, versioned responses get `Vary: X-API-Version`.

A custom resolver implements `Pharaonic\Laravel\ApiVersioning\Contracts\VersionResolver` and returns the raw version or `null`. Add its class to `resolvers`. It is made from the container on every request, so it may take dependencies but must not keep request state.

```php
class AcceptHeaderVersion implements VersionResolver
{
    public function resolve(Request $request): ?string
    {
        preg_match('#application/vnd\.example\.([^+;,\s]+)\+json#', (string) $request->headers->get('Accept'), $matches);

        return $matches[1] ?? null;
    }
}
```

### Versioned JSON resources

`$this->whenVersion($expression, $value, $default = null)` is a `JsonResource` macro. Expressions are `v2` or `=v2`, `>=v2`, `>v2`, `<=v2`, `<v2`. When the expression does not match, the field is removed with Laravel's `MissingValue` (never sent as `null`) and a closure value is never called; with a third argument the field gets that default instead. A resource rendered without a request version (console, queue, a route that is not versioned) matches no expression. The `Pharaonic\Laravel\ApiVersioning\Concerns\InteractsWithApiVersion` trait provides the same method and keeps working.

For structural changes, map a resource to classes per version, for example in `AppServiceProvider::boot()`:

```php
use Pharaonic\Laravel\ApiVersioning\VersionedResources;

app(VersionedResources::class)->register(UserResource::class, [
    'v3' => UserV3Resource::class,
], 'base'); // fallback: "none" (throw), "base" (UserResource) or "previous" (closest earlier mapped class)

return app(VersionedResources::class)->make(UserResource::class, $user);
return app(VersionedResources::class)->collection(UserResource::class, User::paginate());
```

`resolve(UserResource::class, $version = null)` returns the class. Without a request version it returns the registered class.

### Lifecycle headers

```php
'lifecycle' => [
    'v1' => ['status' => 'retired', 'sunset_at' => '2026-01-01'],
    'v2' => ['status' => 'deprecated', 'deprecated_at' => '2026-06-01', 'sunset_at' => '2027-01-01', 'link' => 'https://example.com/migrate'],
    'v3' => ['status' => 'sunset-announced', 'sunset_at' => '2028-01-01'],
],
```

`deprecated` sends `Deprecation` (RFC 9745), `Sunset` (RFC 8594) and `Link` with `rel="deprecation"`; `sunset-announced` sends `Sunset` and `Link` with `rel="sunset"`; `retired` rejects the request with 410 and its `Sunset`. Headers the application sets itself are kept.

### Errors

Rejected requests throw `Pharaonic\Laravel\ApiVersioning\Exceptions\ApiVersionException`, an `HttpException` rendered by Laravel's exception handler. `errorCode()` returns `ApiVersionException::MISSING`, `UNKNOWN`, `CONFLICT`, `UNAVAILABLE` or `RETIRED`. Messages never repeat what the client sent. When rendering it yourself, pass `$e->getHeaders()` to keep `Vary` and `Sunset`, for example in `App\Exceptions\Handler::register()`:

```php
$this->renderable(fn (ApiVersionException $e) => response()->json(
    ['error' => $e->errorCode(), 'message' => $e->getMessage()],
    $e->getStatusCode(),
    $e->getHeaders(),
));
```

## Pitfalls

- Two version groups or versioned routes with the same method and URI fail ("registered more than once") unless the version is in the path. Use one route with constraints or `->controllers()`, or configure `path.prefix`.
- Two version groups giving a route the same name fail. Configure `path.name` or chain `->name('v2.')` on the group.
- Version errors are raised right after the route is matched, before the route's middleware, so an unknown version gets 400 even on a route that needs authentication.
- Registration mistakes (unknown versions, constraints that leave no version, invalid controller maps) throw when routes load, which also stops `route:cache` and other Artisan commands.
- On a route that is not versioned, `{version}` stays a normal parameter and reaches the controller.
- Never fall back to the newest controller or resource silently: a missing mapping is a 404 or an exception unless a fallback is configured.

## Testing

Send the version the way clients do, and assert both the response and the version-specific behavior:

```php
$this->getJson('/users/1', ['X-API-Version' => 'v2'])
    ->assertOk()
    ->assertJsonPath('data.email', $user->email)
    ->assertHeader('Vary', 'X-API-Version');

$this->assertArrayNotHasKey('email', $this->getJson('/users/1?version=v1')->json('data'));
$this->getJson('/users/1', ['X-API-Version' => 'v9'])->assertStatus(400);
```

Cover each version an endpoint exists in, the first version it does not exist in (404), and `route:cache` when the routes use `->controllers()`.
