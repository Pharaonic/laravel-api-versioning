## Troubleshooting

### Every request returns 400 "An API version is required."

The route is versioned and the request names no version. Send the header or query parameter, or set `default`.

### A route returns 404 for one version

The route is not available in that version: check its constraints and its group's, and its `->controllers()` map. A version without a mapped controller is not found unless the route falls back to its own controller.

### "Route [GET|HEAD /users] is registered more than once"

Two version groups or two versioned routes register the same method and URI, which only works when the version is in the path. Use one route with constraints or `->controllers()`, or configure `path.prefix`.

### "Route name [users] is already registered"

Two version groups give a route the same name. Configure `path.name` (`'{version}.'`) or chain `->name('v2.')` on the version group.

### The controller receives "v2" as its first argument

The route is not versioned, so the package does not remove the `{version}` parameter. Add a constraint to the route or its group.

### route:cache fails with an invalid controller

`->controllers()` checks every class and method when the routes load, and Artisan commands load the routes. Fix the map, or the route the message names.

### Changes to versions have no effect

The configuration or routes are cached. Run `php artisan config:cache` and `php artisan route:cache` again, or clear both.
