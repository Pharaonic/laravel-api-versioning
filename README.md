<p align="center"><a href="https://pharaonic.dev/packages/laravel/api-versioning" target="_blank"><img src="https://raw.githubusercontent.com/Pharaonic/laravel-api-versioning/develop/docs/cover.webp"></a></p>

<p align="center">
  <a href="https://php.net" target="_blank"><img src="https://img.shields.io/static/v1?label=PHP&message=8.0&color=blue&style=flat-square" alt="PHP Version : 8.0"></a>
  <a href="https://laravel.com" target="_blank"><img src="https://img.shields.io/static/v1?label=Laravel&message=6.x&color=F05340&style=flat-square" alt="Laravel Version : 6.x"></a>
  <img src="https://img.shields.io/static/v1?label=License&message=MIT&color=brightgreen&style=flat-square" alt="License">
  <img src="https://github.com/Pharaonic/laravel-api-versioning/actions/workflows/build.yml/badge.svg?branch=6.x" alt="Build">
  <br>
  <a href="https://packagist.org/packages/Pharaonic/laravel-api-versioning" target="_blank"><img src="https://img.shields.io/static/v1?label=Packagist&message=pharaonic/laravel-api-versioning&color=blue&logo=packagist&logoColor=white" alt="Source"></a>
  <a href="https://packagist.org/packages/pharaonic/laravel-api-versioning" target="_blank"><img src="https://poser.pugx.org/pharaonic/laravel-api-versioning/v" alt="Packagist Version"></a>
  <a href="https://packagist.org/packages/pharaonic/laravel-api-versioning" target="_blank"><img src="https://poser.pugx.org/pharaonic/laravel-api-versioning/downloads" alt="Packagist Downloads"></a>
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
// config/pharaonic/api-versioning.php: 'versions' => ['v1', 'v2'],

Route::get('users', 'UserController@index')->controllers([
    'v1' => 'UserV1Controller@index',
    'v2' => 'UserV2Controller@index',
]);

Route::get('reports', 'ReportController@index')->sinceVersion('v2');
```

`GET /users` with `X-API-Version: v2` runs `UserV2Controller@index`, with its middleware, bindings and policies. No URL prefix is added unless you configure one.

## Documentation

Full documentation: [API Versioning Documentation](https://pharaonic.dev/packages/laravel/api-versioning).

## Support & Security

See [SUPPORT.md](SUPPORT.md) and [SECURITY.md](SECURITY.md). Contributions are welcome; see [CONTRIBUTING.md](CONTRIBUTING.md).

## License

MIT. See [LICENSE](LICENSE).
