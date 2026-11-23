## Laravel API Versioning (pharaonic/laravel-api-versioning)

API versioning on Laravel's own `Route` facade: version groups and per-route constraints, the version read from a header, the query string, the path or a custom resolver, a controller per version on one route, and versioned JSON resources. Use the `api-versioning-development` skill for the full API.

### Conventions

- Versions are declared in `config/pharaonic/api-versioning.php` (`versions`, oldest first). Every comparison follows that order, never the names, and unknown names throw.
- Only versioned routes (a constraint, a version group or `->controllers()`) resolve a version; other routes are untouched. Give endpoints that exist in every version `->sinceVersion('v1')`.
- No URL prefix is added unless `path.prefix` is set (`'api/{version}'`). With header or query versions, keep one route per method and URI and vary it with `->controllers()` or `whenVersion()`.
- Read the version with `$request->apiVersion()` (a `Version`, or `null`); never store it in a static property or a singleton.
- Bad versions throw `ApiVersionException` (an `HttpException`, rendered by Laravel); read `errorCode()` to build your own error format.

### Main features

@verbatim
<code-snippet name="Version groups and route constraints" lang="php">
use App\Http\Controllers\ReportController;
use App\Http\Controllers\UserController;

Route::sinceVersion('v1')->group(function () {
    Route::apiResource('users', UserController::class);
});

Route::get('reports', [ReportController::class, 'index'])->sinceVersion('v2');
Route::get('legacy', [ReportController::class, 'legacy'])->untilVersion('v2');
</code-snippet>
@endverbatim

@verbatim
<code-snippet name="A controller per version on one route" lang="php">
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserV2Controller;

Route::get('users/{user}', [UserController::class, 'show'])->controllers([
    'v1' => [UserController::class, 'show'],
    'v2' => [UserV2Controller::class, 'show'],
]); // unmapped versions are 404 unless ->controllers([...], true)
</code-snippet>
@endverbatim

@verbatim
<code-snippet name="Fields per version in a JsonResource" lang="php">
public function toArray($request)
{
    return [
        'id' => $this->id,
        'email' => $this->whenVersion('>=v2', fn () => $this->email), // removed below v2
        'plan' => $this->whenVersion('v3', 'premium', 'basic'),
    ];
}
</code-snippet>
@endverbatim
