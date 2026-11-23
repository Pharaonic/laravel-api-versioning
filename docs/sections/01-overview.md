:::badges
- Laravel Package {color=blue}
- {release.label} {color=green}
- {package.license} License {color=purple}
:::

# API Versioning

API Versioning adds versions to the routes you already write with Laravel's `Route` facade. A request names its version in a header, the query string, the path or anything your own resolver reads; the package validates it, checks that the endpoint exists in that version, and can run a different controller per version from one route. JSON resources keep Laravel's `JsonResource` and gain `whenVersion()`.

:::features
### Native Routes {icon="code-brackets"}
Route::version(), sinceVersion(), untilVersion() and versions() work with get, post, match, resources, groups, prefixes, names, domains and middleware.

### Any Version Source {icon="switch"}
Header, query string, path or a custom resolver, with deterministic errors for missing, unknown and conflicting versions.

### Controller per Version {icon="grid"}
One route per method and URI serves a controller per version, with its own middleware, bindings and policies.

### Versioned Resources {icon="document"}
whenVersion() removes fields entirely, and an opt-in map picks a resource class per version.

### Lifecycle Headers {icon="clock"}
Deprecation, Sunset and Link headers per version, and retired versions are rejected.

### Cache Safe {icon="bolt"}
Everything works after route:cache and config:cache, and the version lives on the request only.
:::

:::info No URL is forced
The package never adds `/api` or `/v1` to your URLs. Versions are route metadata; a path prefix is only added when you configure one.
:::
