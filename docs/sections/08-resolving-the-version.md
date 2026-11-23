## Resolving the Version

`resolvers` lists the sources a request may use. All enabled sources are read on every versioned request:

```php title="config/pharaonic/api-versioning.php"
'resolvers' => ['path', 'header', 'query'],
```

| Resolver | Reads | Example |
| --- | --- | --- |
| `path` | the `path.parameter` route parameter, or the version of a `Route::version()` group registered under `path.prefix` | `/api/v2/users` |
| `header` | the `header` request header | `X-API-Version: v2` |
| `query` | the `query` parameter | `/users?version=v2` |

- An unknown value from any source is rejected, so are arrays such as `?version[]=v1`, and empty values count as absent.
- Two sources naming different versions are a conflict; the same version twice is fine.
- Without any value, `default` is used, or the request is rejected.

### Vary

When the `header` resolver is enabled, versioned responses get `Vary: X-API-Version` (merged with any `Vary` your response already has), so shared caches keep one response per version. Query and path versions are part of the URL and need nothing.

### Custom Resolvers

A resolver returns the raw version the request carries, or `null`:

```php title="app/Http/AcceptHeaderVersion.php"
namespace App\Http;

use Illuminate\Http\Request;
use Pharaonic\Laravel\ApiVersioning\Contracts\VersionResolver;

class AcceptHeaderVersion implements VersionResolver
{
    public function resolve(Request $request): ?string
    {
        // Accept: application/vnd.example.v2+json
        preg_match('#application/vnd\.example\.([^+;,\s]+)\+json#', (string) $request->headers->get('Accept'), $matches);

        return $matches[1] ?? null;
    }
}
```

```php title="config/pharaonic/api-versioning.php"
'resolvers' => ['header', App\Http\AcceptHeaderVersion::class],
```

The package validates what it returns and compares it with the other sources. Resolvers are made from the container on every request, so they may take dependencies but should not keep request state. A domain-based version (`v2.api.example.com`) is a custom resolver that reads `$request->getHost()`.
