<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiVersioning\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Pharaonic\Laravel\ApiVersioning\ApiVersioningServiceProvider;
use Pharaonic\Laravel\ApiVersioning\Exceptions\ApiVersionException;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Controllers\ProfileController;
use Pharaonic\Laravel\ApiVersioning\Tests\TestCase;

final class TranslationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('profile', ProfileController::class)->sinceVersion('v1');
    }

    public function test_the_message_of_each_error_follows_the_current_locale(): void
    {
        $this->app->setLocale('ar');

        $cases = [
            ApiVersionException::MISSING => 'يجب تحديد إصدار الـ API.',
            ApiVersionException::UNKNOWN => 'إصدار الـ API غير معروف.',
            ApiVersionException::CONFLICT => 'الطلب يحدد إصدارات API متعارضة.',
            ApiVersionException::UNAVAILABLE => 'غير موجود.',
            ApiVersionException::RETIRED => 'تم إيقاف هذا الإصدار من الـ API.',
        ];

        foreach ($cases as $code => $message) {
            $this->assertSame($message, (new ApiVersionException(400, $code))->localizedMessage(), $code);
        }
    }

    public function test_the_english_lines_are_the_messages_of_the_exception(): void
    {
        foreach ([ApiVersionException::MISSING, ApiVersionException::UNKNOWN, ApiVersionException::CONFLICT, ApiVersionException::UNAVAILABLE, ApiVersionException::RETIRED, 'OTHER'] as $code) {
            $exception = new ApiVersionException(400, $code);

            $this->assertSame($exception->getMessage(), $exception->localizedMessage(), $code);
        }
    }

    public function test_laravel_keeps_rendering_the_english_message(): void
    {
        $this->app->setLocale('ar');

        $this->getJson('/profile')->assertStatus(400)->assertJsonPath('message', 'An API version is required.');
        $this->assertSame('This API version has been retired.', (new ApiVersionException(410, ApiVersionException::RETIRED))->getMessage());
    }

    public function test_unknown_locales_fall_back_to_english_and_the_application_can_override_a_line(): void
    {
        $this->app->setLocale('xx');

        $this->assertSame('Unknown API version.', (new ApiVersionException(400, ApiVersionException::UNKNOWN))->localizedMessage());

        $this->app->setLocale('en');
        $this->app['translator']->addLines(['messages.unknown' => 'No such version.'], 'en', 'api-versioning');

        $this->assertSame('No such version.', (new ApiVersionException(400, ApiVersionException::UNKNOWN))->localizedMessage());
    }

    public function test_the_translations_are_published_with_the_four_tags(): void
    {
        foreach (['pharaonic', 'laravel-api-versioning', 'pharaonic-translations', 'api-versioning-translations'] as $tag) {
            $this->assertContains($this->app->resourcePath('lang/vendor/api-versioning'), ServiceProvider::pathsToPublish(ApiVersioningServiceProvider::class, $tag), $tag);
        }
    }

    public function test_every_locale_has_the_english_keys(): void
    {
        $english = require __DIR__.'/../../resources/lang/en/messages.php';

        foreach (glob(__DIR__.'/../../resources/lang/*/messages.php') ?: [] as $file) {
            $this->assertSame(array_keys($english), array_keys(require $file), $file);
        }
    }
}
