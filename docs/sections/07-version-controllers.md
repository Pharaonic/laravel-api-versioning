## Version Controllers

`->controllers()` maps versions to controllers on one route:

```php title="routes/api.php"
Route::get('users/{user}', 'UserController@show')->controllers([
    'v1' => 'UserV1Controller@show',
    'v2' => ['App\Http\Controllers\UserV2Controller', 'show'],
    'v3' => 'ShowUserV3Controller',      // invokable
]);
```

`GET /users/1` with `X-API-Version: v2` runs `UserV2Controller@show`. Actions are `Class@method`, `[Class::class, 'method']` or an invokable class name; string actions get the group namespace like Laravel's own. Each one is checked when the route is registered: an unknown version, a missing class or method, a closure, or a version the route is not available in is an error.

### Laravel's Pipeline, per Version

The controller is selected when the route is matched, before Laravel gathers the route's middleware. Everything after that is Laravel's own:

- the controller's constructor injection and its `$this->middleware()` (including `only` and `except`);
- implicit and explicit route model binding, scoped bindings and missing models (404);
- method injection, `authorize()`, policies and the `can` middleware.

### No Silent Fallback

A version without a mapping is not found (`status.unavailable`, 404 by default). The route's own controller (`UserController@show` above) is used only when you say so:

```php title="routes/api.php"
Route::get('users', 'UserController@index')
    ->controllers(['v3' => 'UserV3Controller@index'], true); // v1 and v2 use UserController@index
```

Combine maps with constraints to stop at a version: `->controllers([...], true)->untilVersion('v3')`.

### Names, URLs and route:list

The route keeps its name and its own action, so `route('users.show', $user)`, `action('UserController@show')` and `route:list` show the definition you wrote. One route name covers every version of the endpoint.

:::info Why one route per URI
In header and query mode every version shares the URL, and Laravel can only match one route per method and URI. The package keeps that single route and changes only which controller serves the request.
:::
