<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiVersioning;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use InvalidArgumentException;
use LogicException;
use Pharaonic\Laravel\ApiVersioning\Support\CurrentVersion;

/**
 * Opt-in mapping of a resource class to version-specific resource classes,
 * for structural changes that whenVersion() fields do not cover. Nothing is
 * intercepted: `new UserResource()` stays UserResource; only make(),
 * collection() and resolve() consult the map.
 *
 * A version without its own class uses the configured fallback: "none"
 * (an exception, the default), "base" (the registered class itself) or
 * "previous" (the closest earlier mapped version, else the base class).
 * Requests that carry no API version use the base class.
 */
final class VersionedResources
{
    public const FALLBACK_NONE = 'none';

    public const FALLBACK_BASE = 'base';

    public const FALLBACK_PREVIOUS = 'previous';

    /** @var array<class-string<JsonResource>, array{map: array<string, class-string<JsonResource>>, fallback: string}> */
    private array $resources = [];

    public function __construct(
        private VersionRegistry $registry,
        private string $fallback = self::FALLBACK_NONE,
    ) {
        self::assertFallback($fallback);
    }

    /**
     * @param  class-string<JsonResource>  $resource
     * @param  array<string, class-string<JsonResource>>  $versions
     */
    public function register(string $resource, array $versions, ?string $fallback = null): void
    {
        self::assertResource($resource);

        if (isset($this->resources[$resource])) {
            throw new LogicException(sprintf('Resource [%s] already has version mappings.', $resource));
        }

        if ($versions === []) {
            throw new InvalidArgumentException(sprintf('Map resource [%s] to at least one version.', $resource));
        }

        $map = [];

        foreach ($versions as $version => $class) {
            self::assertResource($class);
            $map[$this->registry->get((string) $version)->name()] = $class;
        }

        if ($fallback !== null) {
            self::assertFallback($fallback);
        }

        $this->resources[$resource] = ['map' => $map, 'fallback' => $fallback ?? $this->fallback];
    }

    /**
     * The resource class for the current request's version.
     *
     * @param  class-string<JsonResource>  $resource
     * @return class-string<JsonResource>
     */
    public function resolve(string $resource, Version|string|null $version = null): string
    {
        $version = $version === null ? CurrentVersion::of() : $this->registry->get((string) $version);
        $entry = $this->resources[$resource] ?? null;

        if ($entry === null || $version === null) {
            return $resource;
        }

        if (isset($entry['map'][$version->name()])) {
            return $entry['map'][$version->name()];
        }

        if ($entry['fallback'] === self::FALLBACK_BASE) {
            return $resource;
        }

        if ($entry['fallback'] === self::FALLBACK_PREVIOUS) {
            $previous = $resource;

            foreach ($entry['map'] as $mapped => $class) {
                if ($version->after($mapped)) {
                    $previous = $class;
                }
            }

            return $previous;
        }

        throw new LogicException(sprintf(
            'Resource [%s] has no class for API version [%s] and its fallback is "none".', $resource, $version->name(),
        ));
    }

    /**
     * @param  class-string<JsonResource>  $resource
     */
    public function make(string $resource, mixed $value): JsonResource
    {
        $class = $this->resolve($resource);

        return new $class($value);
    }

    /**
     * Laravel's own collection of the resolved class, so pagination meta,
     * links and wrapping stay native.
     *
     * @param  class-string<JsonResource>  $resource
     */
    public function collection(string $resource, mixed $value): AnonymousResourceCollection
    {
        $class = $this->resolve($resource);

        return $class::collection($value);
    }

    private static function assertResource(string $class): void
    {
        if (! is_a($class, JsonResource::class, true)) {
            throw new InvalidArgumentException(sprintf('[%s] is not a %s class.', $class, JsonResource::class));
        }
    }

    private static function assertFallback(string $fallback): void
    {
        if (! in_array($fallback, [self::FALLBACK_NONE, self::FALLBACK_BASE, self::FALLBACK_PREVIOUS], true)) {
            throw new InvalidArgumentException(sprintf('Resource fallback [%s] must be "none", "base" or "previous".', $fallback));
        }
    }
}
