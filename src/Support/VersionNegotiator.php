<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiVersioning\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Pharaonic\Laravel\ApiVersioning\Contracts\VersionResolver;
use Pharaonic\Laravel\ApiVersioning\Exceptions\ApiVersionException;
use Pharaonic\Laravel\ApiVersioning\Resolvers\HeaderVersionResolver;
use Pharaonic\Laravel\ApiVersioning\Resolvers\PathVersionResolver;
use Pharaonic\Laravel\ApiVersioning\Resolvers\QueryVersionResolver;
use Pharaonic\Laravel\ApiVersioning\Version;
use Pharaonic\Laravel\ApiVersioning\VersionRegistry;

/**
 * Runs the configured resolvers for one request and turns their answers
 * into exactly one declared version, or a client error.
 *
 * Stateless between requests: it only keeps what configuration defines.
 *
 * @internal
 */
final class VersionNegotiator
{
    private const STATUSES = [
        ApiVersionException::MISSING => ['missing', 400],
        ApiVersionException::UNKNOWN => ['unknown', 400],
        ApiVersionException::CONFLICT => ['conflict', 400],
        ApiVersionException::UNAVAILABLE => ['unavailable', 404],
        ApiVersionException::RETIRED => ['retired', 410],
    ];

    /** @var list<VersionResolver|string>|null built-in resolvers, or custom resolver classes made per request */
    private ?array $resolvers = null;

    private ?string $header = null;

    public function __construct(
        private Container $container,
        private Repository $config,
        private VersionRegistry $registry,
    ) {
    }

    /**
     * @throws ApiVersionException
     */
    public function negotiate(Request $request): Version
    {
        $found = [];

        foreach ($this->resolvers() as $resolver) {
            if (is_string($resolver)) {
                $resolver = $this->container->make($resolver);

                if (! $resolver instanceof VersionResolver) {
                    throw new InvalidArgumentException(sprintf('API version resolvers must implement %s.', VersionResolver::class));
                }
            }

            $value = $resolver->resolve($request);
            $value = $value === null ? '' : trim($value);

            if ($value === '') {
                continue;
            }

            if (! $this->registry->has($value)) {
                throw $this->reject(ApiVersionException::UNKNOWN);
            }

            $found[$value] = true;
        }

        if (count($found) > 1) {
            throw $this->reject(ApiVersionException::CONFLICT);
        }

        if ($found !== []) {
            return $this->registry->get((string) array_key_first($found));
        }

        return $this->registry->default() ?? throw $this->reject(ApiVersionException::MISSING);
    }

    /**
     * The request header the version is read from, when the header resolver is enabled.
     */
    public function header(): ?string
    {
        $this->resolvers();

        return $this->header;
    }

    /**
     * @param  array<string, string>  $headers
     */
    public function reject(string $code, array $headers = []): ApiVersionException
    {
        [$key, $default] = self::STATUSES[$code];
        $status = $this->config->get('pharaonic.api-versioning.status.'.$key, $default);

        if (! is_int($status) || $status < 400 || $status > 599) {
            throw new InvalidArgumentException(sprintf('[pharaonic.api-versioning.status.%s] must be an HTTP error status code.', $key));
        }

        if (($header = $this->header()) !== null) {
            $headers['Vary'] = $header;
        }

        return new ApiVersionException($status, $code, $headers);
    }

    /**
     * @return list<VersionResolver|string>
     */
    private function resolvers(): array
    {
        if ($this->resolvers !== null) {
            return $this->resolvers;
        }

        $names = $this->config->get('pharaonic.api-versioning.resolvers', []);

        if (! is_array($names) || $names === []) {
            throw new InvalidArgumentException('[pharaonic.api-versioning.resolvers] must list at least one resolver.');
        }

        $resolvers = [];

        foreach ($names as $name) {
            $resolvers[] = match ($name) {
                'path' => new PathVersionResolver($this->string('path.parameter', 'version')),
                'header' => new HeaderVersionResolver($this->header = $this->string('header', 'X-API-Version')),
                'query' => new QueryVersionResolver($this->string('query', 'version')),
                default => is_string($name) && is_subclass_of($name, VersionResolver::class) ? $name : throw new InvalidArgumentException(sprintf(
                    'API version resolver [%s] must be "path", "header", "query" or a class implementing %s.',
                    is_string($name) ? $name : get_debug_type($name),
                    VersionResolver::class,
                )),
            };
        }

        return $this->resolvers = $resolvers;
    }

    private function string(string $key, string $default): string
    {
        $value = $this->config->get('pharaonic.api-versioning.'.$key, $default);

        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException(sprintf('[pharaonic.api-versioning.%s] must be a non-empty string.', $key));
        }

        return $value;
    }
}
