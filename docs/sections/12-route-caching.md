## Route Caching and Workers

### route:cache and config:cache

Version constraints and controller maps are stored as plain strings in each route's action, so they are part of the route cache:

```bash title="Terminal" no-line-numbers
php artisan config:cache
php artisan route:cache
```

The package is tested by caching the routes, booting a new application that only loads the cached file, and comparing routes and responses: header controllers, model binding, controller middleware, policies, path groups and route names behave the same. `route:clear` and a rebuild work as usual.

:::warning Rebuild both caches together
Routes are validated against `versions` when they are registered. After changing `versions`, rebuild the config cache and the route cache.
:::

### Long-running Workers

The resolved version is stored on the request (`$request->attributes`), never in a static property or a singleton, so a worker that serves many requests (Laravel Octane, queue workers rendering resources, sequential test requests) never carries a version from one request to the next. The package's services only hold configuration.

Laravel reuses matched route objects between requests and keeps per-request state on them, such as their parameters and controller instance. For a route with `->controllers()`, the package sets the version's controller the same way, on every request before anything reads it, and points the route back at its own action when the request leaves it.

:::info Tested
Repeated requests with alternating versions on one application are tested, including controller middleware. The package does not run Laravel Octane itself in its test suite.
:::
