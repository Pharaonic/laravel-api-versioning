<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiVersioning\Exceptions;

use Illuminate\Container\Container;
use Illuminate\Contracts\Translation\Translator;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A request rejected because of its API version. Laravel renders it like any
 * HTTP exception; an application that needs its own error format catches it
 * and reads errorCode(). Messages never repeat the version the client sent.
 * getMessage() stays in English for logs and Laravel's own rendering;
 * localizedMessage() is the message in the current locale, from the
 * api-versioning::messages translations, for the application's error format.
 */
final class ApiVersionException extends HttpException
{
    /** The request names no version and no default version is configured. */
    public const MISSING = 'MISSING_API_VERSION';

    /** The request names a version that is not declared. */
    public const UNKNOWN = 'UNKNOWN_API_VERSION';

    /** Two version sources of the request name different versions. */
    public const CONFLICT = 'VERSION_CONFLICT';

    /** The endpoint does not exist in the requested version. */
    public const UNAVAILABLE = 'API_VERSION_UNAVAILABLE';

    /** The requested version is retired. */
    public const RETIRED = 'API_VERSION_RETIRED';

    private const MESSAGES = [
        self::MISSING => 'An API version is required.',
        self::UNKNOWN => 'Unknown API version.',
        self::CONFLICT => 'The request names conflicting API versions.',
        self::UNAVAILABLE => 'Not Found.',
        self::RETIRED => 'This API version has been retired.',
    ];

    /** The translation key of each message, in api-versioning::messages. */
    private const KEYS = [
        self::MISSING => 'missing',
        self::UNKNOWN => 'unknown',
        self::CONFLICT => 'conflict',
        self::UNAVAILABLE => 'unavailable',
        self::RETIRED => 'retired',
    ];

    private string $errorCode;

    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(int $status, string $errorCode, array $headers = [])
    {
        parent::__construct($status, self::MESSAGES[$errorCode] ?? 'Invalid API version.', null, $headers);

        $this->errorCode = $errorCode;
    }

    /**
     * One of the class constants, such as ApiVersionException::UNKNOWN.
     */
    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * The message in the current locale, or the English message when the locale has no line for it.
     */
    public function localizedMessage(): string
    {
        $container = Container::getInstance();

        if (! $container->bound('translator')) {
            return $this->getMessage();
        }

        $translator = $container->make('translator');
        $line = 'api-versioning::messages.'.(self::KEYS[$this->errorCode] ?? 'invalid');
        $value = $translator instanceof Translator ? $translator->get($line) : null;

        return is_string($value) && $value !== $line ? $value : $this->getMessage();
    }
}
