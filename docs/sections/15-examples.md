## Examples

### 1. Header Versioning with Shared Routes

Every endpoint lives once; versions change controllers or fields.

```php title="routes/api.php"
use App\Http\Controllers\TeamController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserV2Controller;

Route::sinceVersion('v1')->group(function () {
    Route::get('users', [UserController::class, 'index']);
    Route::get('users/{user}', [UserController::class, 'show'])->controllers([
        'v1' => [UserController::class, 'show'],
        'v2' => [UserV2Controller::class, 'show'],
    ], true);
});

Route::sinceVersion('v2')->group(function () {
    Route::apiResource('teams', TeamController::class);
});
```

### 2. Versions in the URL

- ===Configuration

  ```php title="config/pharaonic/api-versioning.php"
  'versions' => ['v1', 'v2'],
  'resolvers' => ['path'],
  'path' => ['prefix' => '{version}', 'name' => '{version}.'],
  ```

- ===Routes

  ```php title="routes/api.php"
  Route::version('v1', function () {
      Route::apiResource('users', 'V1\UserController');
  });

  Route::version('v2')->middleware('auth:api')->group(function () {
      Route::apiResource('users', 'V2\UserController');
  });

  // /api/v1/users → v1.users.index, /api/v2/users → v2.users.index
  ```

### 3. Adding a Field in v2

```php title="app/Http/Resources/OrderResource.php"
public function toArray($request)
{
    return [
        'id' => $this->id,
        'total' => $this->total,
        'currency' => $this->whenVersion('>=v2', fn () => $this->currency),
        'items' => OrderItemResource::collection($this->whenLoaded('items')),
    ];
}
```

### 4. Retiring a Version

```php title="config/pharaonic/api-versioning.php"
'lifecycle' => [
    'v1' => ['status' => 'deprecated', 'deprecated_at' => '2026-06-01', 'sunset_at' => '2026-12-31', 'link' => 'https://example.com/v2-migration'],
],
// After the sunset date: 'v1' => ['status' => 'retired'], and v1 requests get 410.
```
