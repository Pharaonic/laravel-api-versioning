## Version Groups

`Route::version()` registers routes that belong to one version. It takes a closure or a route file, or returns a registrar that accepts Laravel's group attributes.

```php title="routes/api.php"
Route::version('v1', function () {
    Route::apiResource('users', 'UserV1Controller');
});

Route::version('v2')
    ->middleware('auth:api')
    ->group(base_path('routes/api/v2.php'));
```

`middleware()`, `prefix()`, `name()`, `domain()`, `where()` and `namespace()` are Laravel's own and compose with every outer group. Inside the group, `Route::get()`, `post()`, `match()`, `any()`, `resource()` and `apiResource()` work as usual, and nested `Route::prefix()`, `name()` and `middleware()` groups keep their meaning.

### Path Prefix Templates

Without configuration a version group adds no URL prefix, so in header or query mode two groups cannot both register `GET users`. To put the version in the URL, configure templates:

```php title="config/pharaonic/api-versioning.php"
'path' => [
    'prefix' => 'api/{version}',
    'name' => '{version}.',
],
```

```php title="routes/api.php"
Route::version('v1', function () {
    Route::get('users', 'UserV1Controller@index')->name('users'); // /api/v1/users, v1.users
});

Route::version('v2', function () {
    Route::get('users', 'UserV2Controller@index')->name('users'); // /api/v2/users, v2.users
});
```

The template is added inside every prefix and name already in effect, including the ones chained on `Route::version()`: `Route::prefix('services')` around it gives `services/api/v1/users`, and `Route::version('v1')->prefix('admin')` gives `admin/api/v1/users`. A route registered under a template knows its version from the path, so the request needs no header, and a header that names another version is a conflict.

### Constraint Groups

`Route::versions()`, `Route::sinceVersion()` and `Route::untilVersion()` constrain a group without adding a prefix. They are the way to version resource routes, because `Route::apiResource()` returns a pending registration rather than a route:

```php title="routes/api.php"
Route::sinceVersion('v2')->middleware('auth:api')->group(function () {
    Route::apiResource('reports', 'ReportController');
});

Route::versions('v1', 'v2')->group(function () {
    Route::get('legacy/export', 'ExportController@legacy');
});
```

Constraints combine: `Route::sinceVersion('v2')->untilVersion('v3')`, and nested groups and route-level constraints always narrow.

:::warning One version group at a time
`Route::version()` groups cannot be nested. Inside a version group, use constraint groups or route constraints.
:::

### Registering a Single Route

The registrar also registers one route:

```php title="routes/api.php"
Route::version('v2')->middleware('auth:api')->get('reports', 'ReportController@index');
```

Resources need `->group()`.
