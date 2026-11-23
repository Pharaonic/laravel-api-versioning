## Configuration

The configuration lives in `config/pharaonic/api-versioning.php` (`pharaonic.api-versioning`). Every key is optional except `versions`.

| Key | Default | Description |
| --- | --- | --- |
| `versions` | `['v1']` | Supported versions, oldest first. Every comparison follows this order, never the names, so `2024-01`, `beta` or `v10` work. Names use letters, digits, `.`, `_` and `-`. |
| `default` | `null` | The version of a request that names none. `null` rejects such requests. |
| `resolvers` | `['path', 'header']` | Where a request names its version: `path`, `header`, `query` or a class implementing `VersionResolver`, in any combination. |
| `header` | `X-API-Version` | The header the `header` resolver reads. |
| `query` | `version` | The query parameter the `query` resolver reads. |
| `path.prefix` | `null` | A prefix template for `Route::version()` groups, such as `api/{version}` or `{version}`. |
| `path.name` | `null` | A route name prefix template for `Route::version()` groups, such as `{version}.`. |
| `path.parameter` | `version` | The route parameter a shared route reads its version from, as in `Route::prefix('{version}')`. |
| `status.missing` | `400` | Status when no version is given and there is no default. |
| `status.unknown` | `400` | Status for a version that is not declared. |
| `status.conflict` | `400` | Status when two sources name different versions. |
| `status.unavailable` | `404` | Status when the endpoint does not exist in the version. |
| `status.retired` | `410` | Status for a retired version. |
| `resource_fallback` | `none` | What `VersionedResources` does for a version without its own class: `none`, `base` or `previous`. |
| `lifecycle` | `[]` | Status, dates and link per version; see [Lifecycle Headers](#lifecycle). |

```php title="config/pharaonic/api-versioning.php"
return [
    'versions' => ['v1', 'v2', 'v3'],
    'default' => 'v1',
    'resolvers' => ['header', 'query'],
    'header' => 'X-API-Version',
    'lifecycle' => [
        'v1' => ['status' => 'deprecated', 'deprecated_at' => '2026-06-01', 'sunset_at' => '2027-01-01'],
    ],
];
```

:::warning Cache your configuration and routes together
Route constraints are checked against `versions` when routes are registered. After changing the version list, run `php artisan config:cache` and `php artisan route:cache` again.
:::
