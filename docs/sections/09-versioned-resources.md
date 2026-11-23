## Versioned Resources

Resources stay Laravel's `JsonResource`. Add the `InteractsWithApiVersion` trait to use `whenVersion()`:

```php title="app/Http/Resources/UserResource.php"
namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Pharaonic\Laravel\ApiVersioning\Concerns\InteractsWithApiVersion;

class UserResource extends JsonResource
{
    use InteractsWithApiVersion;

    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->whenVersion('>=v2', fn () => $this->email),
            'legacy_name' => $this->whenVersion('<v2', fn () => $this->name),
            'plan' => $this->whenVersion('v3', 'premium', 'basic'),
        ];
    }
}
```

| Expression | Matches |
| --- | --- |
| `v2` or `=v2` | exactly v2 |
| `>=v2` / `>v2` | v2 and later / later than v2 |
| `<=v2` / `<v2` | v2 and earlier / earlier than v2 |

When the expression does not match, the field is removed with Laravel's `MissingValue`, never sent as `null`, and a closure value is never called, so its queries never run. With a third argument the field gets that default instead. Unknown versions and invalid expressions throw. A resource rendered without a resolved version (a console command, a queued job, a route that is not versioned) matches no expression.

Nested resources, `when()`, `whenLoaded()`, `mergeWhen()`, collections and paginated collections keep Laravel's behavior and use the same request version.

:::info On Laravel 9 and later
`JsonResource` is macroable from Laravel 9, and the package also registers `whenVersion()` as a macro there. On this line, use the trait.
:::

### Resource Classes per Version

For structural changes, map a resource to version-specific classes in a service provider's `boot()` method:

```php title="app/Providers/AppServiceProvider.php"
use App\Http\Resources\UserResource;
use App\Http\Resources\UserV3Resource;
use Pharaonic\Laravel\ApiVersioning\VersionedResources;

public function boot()
{
    $this->app->make(VersionedResources::class)->register(UserResource::class, [
        'v3' => UserV3Resource::class,
    ], 'base');
}
```

The map lives as long as the application, which is the whole worker under Laravel Octane, so register it while the application boots, never during a request. A resource can be registered once; registering it again throws a `LogicException`.

Then build resources through the service:

```php title="app/Http/Controllers/UserController.php"
public function index(VersionedResources $resources)
{
    return $resources->collection(UserResource::class, User::paginate());
}

public function show(VersionedResources $resources, User $user)
{
    return $resources->make(UserResource::class, $user);
}
```

`make()` returns the resolved resource, `collection()` returns Laravel's own collection of it (pagination `meta`, `links` and wrapping unchanged), and `resolve(UserResource::class)` returns the class name. `new UserResource($user)` is never intercepted.

| Fallback | A version without its own class |
| --- | --- |
| `none` (default) | throws a `LogicException` |
| `base` | uses the registered class (`UserResource`) |
| `previous` | uses the closest earlier mapped class, else the registered class |

Set the default with `resource_fallback`, or per resource with the third argument of `register()`. The newest class is never used for a version it was not mapped to.
