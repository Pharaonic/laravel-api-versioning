<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Pharaonic\Laravel\ApiVersioning\ApiVersioningServiceProvider;
use Pharaonic\Laravel\ApiVersioning\Exceptions\ApiVersionException;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Controllers\ProfileController;

beforeEach(function () {
    Route::get('profile', ProfileController::class)->sinceVersion('v1');
});

test('the message of each error follows the current locale', function (string $code, string $message) {
    $this->app->setLocale('ar');

    expect((new ApiVersionException(400, $code))->localizedMessage())->toBe($message);
})->with([
    [ApiVersionException::MISSING, 'يجب تحديد إصدار الـ API.'],
    [ApiVersionException::UNKNOWN, 'إصدار الـ API غير معروف.'],
    [ApiVersionException::CONFLICT, 'الطلب يحدد إصدارات API متعارضة.'],
    [ApiVersionException::UNAVAILABLE, 'غير موجود.'],
    [ApiVersionException::RETIRED, 'تم إيقاف هذا الإصدار من الـ API.'],
]);

test('the english lines are the messages of the exception', function (string $code) {
    $exception = new ApiVersionException(400, $code);

    expect($exception->localizedMessage())->toBe($exception->getMessage());
})->with([ApiVersionException::MISSING, ApiVersionException::UNKNOWN, ApiVersionException::CONFLICT, ApiVersionException::UNAVAILABLE, ApiVersionException::RETIRED, 'OTHER']);

test('laravel keeps rendering the english message', function () {
    $this->app->setLocale('ar');

    $this->getJson('/profile')->assertStatus(400)->assertJsonPath('message', 'An API version is required.');
    expect((new ApiVersionException(410, ApiVersionException::RETIRED))->getMessage())->toBe('This API version has been retired.');
});

test('unknown locales fall back to english and the application can override a line', function () {
    $this->app->setLocale('xx');

    expect((new ApiVersionException(400, ApiVersionException::UNKNOWN))->localizedMessage())->toBe('Unknown API version.');

    $this->app->setLocale('en');
    $this->app['translator']->addLines(['messages.unknown' => 'No such version.'], 'en', 'api-versioning');

    expect((new ApiVersionException(400, ApiVersionException::UNKNOWN))->localizedMessage())->toBe('No such version.');
});

test('the translations are published with the four tags', function (string $tag) {
    expect(ServiceProvider::pathsToPublish(ApiVersioningServiceProvider::class, $tag))->toContain($this->app->resourcePath('lang/vendor/api-versioning'));
})->with(['pharaonic', 'laravel-api-versioning', 'pharaonic-translations', 'api-versioning-translations']);

test('every locale has the english keys', function () {
    $english = require __DIR__.'/../../resources/lang/en/messages.php';

    foreach (glob(__DIR__.'/../../resources/lang/*/messages.php') ?: [] as $file) {
        expect(array_keys(require $file))->toBe(array_keys($english));
    }
});
