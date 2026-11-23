<p align="center"><a href="https://pharaonic.dev/packages/laravel/api-versioning" target="_blank"><img src="https://raw.githubusercontent.com/Pharaonic/laravel-api-versioning/develop/docs/cover.webp"></a></p>

<p align="center">
  <a href="https://php.net" target="_blank"><img src="https://img.shields.io/static/v1?label=PHP&message=8.1%20|%208.2%20|%208.3&color=blue&style=flat-square" alt="PHP Version : 8.1 | 8.2 | 8.3"></a>
  <a href="https://laravel.com" target="_blank"><img src="https://img.shields.io/static/v1?label=Laravel&message=10.x&color=F05340&style=flat-square" alt="Laravel Version : 10.x"></a>
  <img src="https://img.shields.io/static/v1?label=License&message=MIT&color=brightgreen&style=flat-square" alt="License">
  <img src="https://github.com/Pharaonic/laravel-api-versioning/actions/workflows/build.yml/badge.svg?branch=10.x" alt="Build">
  <br>
  <a href="https://packagist.org/packages/Pharaonic/laravel-api-versioning" target="_blank"><img src="https://img.shields.io/static/v1?label=Packagist&message=pharaonic/laravel-api-versioning&color=blue&logo=packagist&logoColor=white" alt="Source"></a>
  <a href="https://packagist.org/packages/pharaonic/laravel-api-versioning" target="_blank"><img src="https://poser.pugx.org/pharaonic/laravel-api-versioning/v" alt="Packagist Version"></a>
  <a href="https://packagist.org/packages/pharaonic/laravel-api-versioning" target="_blank"><img src="https://poser.pugx.org/pharaonic/laravel-api-versioning/downloads" alt="Packagist Downloads"></a>
  <a href="https://pharaonic.dev/packages/laravel/api-versioning#ai-ready" target="_blank"><img src="https://img.shields.io/static/v1?label=AI%20Ready&message=Laravel%20Boost&color=8B5CF6&style=flat-square" alt="AI Ready: Laravel Boost"></a>
</p>

<h3 align="center">Laravel-native API versioning: version groups and constraints on Route, header, query and path resolution, version-specific controllers, and versioned JSON resources.</h3>
<br>

## Installation

```bash
composer require pharaonic/laravel-api-versioning
```

The service provider is registered automatically. Publish the configuration when you need to change it:

```bash
php artisan vendor:publish --tag=api-versioning-config
```

## Usage

```php
use App\Http\Controllers\ReportController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserV1Controller;
use App\Http\Controllers\UserV2Controller;

// config/pharaonic/api-versioning.php: 'versions' => ['v1', 'v2'],

Route::get('users', [UserController::class, 'index'])->controllers([
    'v1' => [UserV1Controller::class, 'index'],
    'v2' => [UserV2Controller::class, 'index'],
]);

Route::get('reports', [ReportController::class, 'index'])->sinceVersion('v2');
```

`GET /users` with `X-API-Version: v2` runs `UserV2Controller@index`, with its middleware, bindings and policies. No URL prefix is added unless you configure one.

## Documentation

Full documentation: [API Versioning Documentation](https://pharaonic.dev/packages/laravel/api-versioning).

## Support & Security

See [SUPPORT.md](SUPPORT.md) and [SECURITY.md](SECURITY.md). Contributions are welcome; see [CONTRIBUTING.md](CONTRIBUTING.md).

## License

MIT. See [LICENSE](LICENSE).
