## Installation

Install the package with Composer. The service provider and the `Route`, `Request` and resource helpers are registered automatically.

### Requirements

- PHP 8.1, 8.2 or 8.3
- Laravel 10.x

### Composer Installation

```bash title="Terminal" no-line-numbers
composer require pharaonic/laravel-api-versioning
```

### Publish Configuration

Publish the configuration file to `config/pharaonic/api-versioning.php`:

```bash title="Terminal" no-line-numbers
php artisan vendor:publish --tag=api-versioning-config
```

Then list your versions, oldest first:

```php title="config/pharaonic/api-versioning.php"
'versions' => ['v1', 'v2', 'v3'],
```

:::success Installation Complete
You're all set! Add a version constraint or a version group to your API routes.
:::
