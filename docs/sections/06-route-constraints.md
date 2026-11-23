## Route Constraints

Every route gets the same constraints as groups:

```php title="routes/api.php"
Route::get('profile', 'ProfileController@show')->versions('v1', 'v2', 'v3');
Route::get('reports', 'ReportController@index')->sinceVersion('v2');
Route::get('legacy', 'LegacyController@index')->untilVersion('v2');
Route::get('window', 'WindowController@index')->sinceVersion('v2')->untilVersion('v3');
```

| Method | Available in |
| --- | --- |
| `->versions('v1', 'v3')` | exactly the listed versions (a list or an array, no duplicates) |
| `->sinceVersion('v2')` | v2 and every later version, including versions added later |
| `->untilVersion('v2')` | v2 and every earlier version |

Both bounds are inclusive and follow the order of `versions` in the configuration. Unknown versions, duplicates and constraints that leave no version fail when the routes are registered, with the route in the message.

### Shared Routes with a Path Parameter

A single route can take the version from its own URL:

```php title="routes/api.php"
Route::prefix('api/{version}')->group(function () {
    Route::get('profile', 'ProfileController@show')->versions('v1', 'v2');
});
```

`/api/v2/profile` resolves `v2`, `/api/v3/profile` is not found and `/api/v9/profile` is an unknown version. The `{version}` parameter is removed before route model binding and before the controller is called, so it never shifts controller arguments and never reaches `Route::bind('version')`. The parameter name is `path.parameter`.

:::warning A `{version}` parameter of your own
On a versioned route, the `path.parameter` route parameter always means the API version. When your URLs already use `{version}` for something else, such as `/documents/{document}/versions/{version}`, set `path.parameter` to another name, such as `api_version`.
:::

### Duplicate Definitions

Laravel silently keeps the last of two routes with the same method and URI. For versioned routes that would hide one version, so the package fails instead: two version groups or two versioned routes with the same method and URI, or a plain route that replaces a versioned one, are registration errors. Serve every version of an endpoint from one route (constraints or `->controllers()`), or give each version its own path prefix.
