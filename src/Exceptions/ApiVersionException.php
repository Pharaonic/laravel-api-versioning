<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiVersioning\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A request rejected because of its API version. Laravel renders it like any
 * HTTP exception; an application that needs its own error format catches it
 * and reads errorCode(). Messages never repeat the version the client sent.
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
}
