## Basic Usage

A route becomes versioned when it gets a version constraint, either from a group or on the route itself. Versioned routes resolve the request's version before anything else runs; other routes are untouched.

```php title="routes/api.php"
use App\Http\Controllers\LegacyController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;

Route::get('profile', [ProfileController::class, 'show'])->sinceVersion('v1');
Route::get('reports', [ReportController::class, 'index'])->sinceVersion('v2');
Route::get('legacy', [LegacyController::class, 'index'])->untilVersion('v2');
```

With `X-API-Version: v1`, `/reports` is not found and `/legacy` works; with `v3`, `/reports` works and `/legacy` is not found.

### Reading the Version

`Request::apiVersion()` returns the resolved `Version`, or `null` on a route that is not versioned:

```php title="app/Http/Controllers/ProfileController.php"
public function show(Request $request)
{
    $version = $request->apiVersion();

    $version->name();          // "v2"
    $version->is('v2');        // true
    $version->atLeast('v2');   // true
    $version->before('v3');    // true
}
```

### Resolution Order

For every versioned route the package:

1. asks every enabled resolver for a version and rejects unknown values;
2. rejects the request when two resolvers name different versions;
3. uses the configured `default` when no resolver found one, or rejects the request;
4. rejects retired versions, then versions the route is not available in;
5. selects the version's controller, when the route maps controllers.

:::tip Shared routes are the norm
Most endpoints exist in every version. Give them `->sinceVersion('v1')` (or wrap them in `Route::sinceVersion('v1')->group(...)`), and change them per version with `whenVersion()` in resources or with `->controllers()`.
:::
