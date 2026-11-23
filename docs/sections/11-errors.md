## Errors

Rejected requests throw `Pharaonic\Laravel\ApiVersioning\Exceptions\ApiVersionException`, an `HttpException` that Laravel renders like any other. Messages never repeat what the client sent.

| `errorCode()` | Default status | When |
| --- | --- | --- |
| `ApiVersionException::MISSING` (`MISSING_API_VERSION`) | 400 | no version and no `default` |
| `ApiVersionException::UNKNOWN` (`UNKNOWN_API_VERSION`) | 400 | a version that is not declared |
| `ApiVersionException::CONFLICT` (`VERSION_CONFLICT`) | 400 | two sources name different versions |
| `ApiVersionException::UNAVAILABLE` (`API_VERSION_UNAVAILABLE`) | 404 | the endpoint does not exist in the version |
| `ApiVersionException::RETIRED` (`API_VERSION_RETIRED`) | 410 | the version is retired |

Change the statuses with `status.*`. When the header resolver is enabled, these errors carry `Vary` too. To use your own error format, render the exception in your exception handler:

```php title="app/Exceptions/Handler.php"
use Pharaonic\Laravel\ApiVersioning\Exceptions\ApiVersionException;

public function render($request, Throwable $e)
{
    if ($e instanceof ApiVersionException) {
        return response()->json(['error' => $e->errorCode()], $e->getStatusCode(), $e->getHeaders());
    }

    return parent::render($request, $e);
}
```

Version errors are found right after the route is matched, before the route's middleware: a request with an unknown version gets 400 even when the route needs authentication.

### Registration Errors

Mistakes in route definitions fail when the routes are registered, with the route in the message: unknown or duplicate versions, constraints that leave no version, invalid controller maps, nested `Route::version()` groups, duplicate method and URI definitions, and duplicate route names in version groups. They are `InvalidArgumentException` or `LogicException`, and they also stop `route:cache` and other Artisan commands, which load the routes.
