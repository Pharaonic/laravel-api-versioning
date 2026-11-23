<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiVersioning;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/**
 * The supported API versions in their declared order, the optional default
 * version and the lifecycle of each version. Built once from configuration
 * and never changed afterwards.
 */
final class VersionRegistry
{
    public const ACTIVE = 'active';

    public const DEPRECATED = 'deprecated';

    public const SUNSET_ANNOUNCED = 'sunset-announced';

    public const RETIRED = 'retired';

    private const NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]*$/';

    /** @var array<string, int> */
    private array $positions = [];

    /** @var array<string, Version> */
    private array $versions = [];

    private ?string $default;

    /** @var array<string, array{status: string, deprecated_at: ?int, sunset_at: ?int, link: ?string}> */
    private array $lifecycle = [];

    /**
     * @param  array<array-key, mixed>  $versions  version names, oldest first
     * @param  array<array-key, mixed>  $lifecycle  lifecycle entries keyed by version name
     */
    public function __construct(array $versions, ?string $default = null, array $lifecycle = [])
    {
        if ($versions === []) {
            throw new InvalidArgumentException('API versioning needs at least one version in [versions].');
        }

        foreach (array_values($versions) as $position => $name) {
            if (! is_string($name) || preg_match(self::NAME_PATTERN, $name) !== 1) {
                throw new InvalidArgumentException(sprintf(
                    'API version names must be strings of letters, digits, ".", "_" or "-" that start with a letter or digit; got [%s].',
                    is_scalar($name) ? (string) $name : get_debug_type($name),
                ));
            }

            if (isset($this->positions[$name])) {
                throw new InvalidArgumentException(sprintf('API version [%s] is declared more than once.', $name));
            }

            $this->positions[$name] = $position;
            $this->versions[$name] = new Version($name, $this);
        }

        if ($default !== null && ! isset($this->positions[$default])) {
            throw new InvalidArgumentException(sprintf('The default API version [%s] is not a declared version.', $default));
        }

        $this->default = $default;

        foreach ($lifecycle as $name => $entry) {
            $this->lifecycle[$this->known((string) $name)] = self::parseLifecycle((string) $name, $entry);
        }
    }

    public static function fromConfig(Repository $config): self
    {
        $versions = $config->get('pharaonic.api-versioning.versions');
        $default = $config->get('pharaonic.api-versioning.default');
        $lifecycle = $config->get('pharaonic.api-versioning.lifecycle', []);

        if (! is_array($versions)) {
            throw new InvalidArgumentException('[pharaonic.api-versioning.versions] must be a list of version names.');
        }

        if ($default !== null && ! is_string($default)) {
            throw new InvalidArgumentException('[pharaonic.api-versioning.default] must be a version name or null.');
        }

        if (! is_array($lifecycle)) {
            throw new InvalidArgumentException('[pharaonic.api-versioning.lifecycle] must be an array keyed by version name.');
        }

        return new self($versions, $default, $lifecycle);
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return array_keys($this->positions);
    }

    public function has(string $name): bool
    {
        return isset($this->positions[$name]);
    }

    public function get(string $name): Version
    {
        return $this->versions[$this->known($name)];
    }

    public function default(): ?Version
    {
        return $this->default === null ? null : $this->versions[$this->default];
    }

    /**
     * Negative when $a comes before $b, zero when they are the same version, positive after.
     */
    public function compare(Version|string $a, Version|string $b): int
    {
        return $this->positions[$this->known((string) $a)] <=> $this->positions[$this->known((string) $b)];
    }

    public function status(Version|string $version): string
    {
        return $this->lifecycle[$this->known((string) $version)]['status'] ?? self::ACTIVE;
    }

    /**
     * The Deprecation (RFC 9745), Sunset (RFC 8594) and Link headers of a version.
     *
     * @internal
     *
     * @return array<string, string>
     */
    public function lifecycleHeaders(Version|string $version): array
    {
        $entry = $this->lifecycle[$this->known((string) $version)] ?? null;

        if ($entry === null || $entry['status'] === self::ACTIVE) {
            return [];
        }

        $headers = [];

        if ($entry['deprecated_at'] !== null && $entry['status'] !== self::RETIRED) {
            $headers['Deprecation'] = '@'.$entry['deprecated_at'];
        }

        if ($entry['sunset_at'] !== null) {
            $headers['Sunset'] = gmdate('D, d M Y H:i:s', $entry['sunset_at']).' GMT';
        }

        if ($entry['link'] !== null) {
            $relation = $entry['status'] === self::DEPRECATED ? 'deprecation' : 'sunset';
            $headers['Link'] = '<'.$entry['link'].'>; rel="'.$relation.'"';
        }

        return $headers;
    }

    private function known(string $name): string
    {
        if (! isset($this->positions[$name])) {
            throw new InvalidArgumentException(sprintf(
                'Unknown API version [%s]. Declared versions: [%s].',
                $name,
                implode(', ', array_keys($this->positions)),
            ));
        }

        return $name;
    }

    /**
     * @return array{status: string, deprecated_at: ?int, sunset_at: ?int, link: ?string}
     */
    private static function parseLifecycle(string $name, mixed $entry): array
    {
        if (! is_array($entry)) {
            throw new InvalidArgumentException(sprintf('The lifecycle of API version [%s] must be an array.', $name));
        }

        $status = $entry['status'] ?? self::ACTIVE;

        if (! in_array($status, [self::ACTIVE, self::DEPRECATED, self::SUNSET_ANNOUNCED, self::RETIRED], true)) {
            throw new InvalidArgumentException(sprintf(
                'The lifecycle status of API version [%s] must be one of [active, deprecated, sunset-announced, retired].',
                $name,
            ));
        }

        $deprecatedAt = self::timestamp($name, 'deprecated_at', $entry['deprecated_at'] ?? null);
        $sunsetAt = self::timestamp($name, 'sunset_at', $entry['sunset_at'] ?? null);
        $link = $entry['link'] ?? null;

        if ($status === self::DEPRECATED && $deprecatedAt === null) {
            throw new InvalidArgumentException(sprintf('Deprecated API version [%s] needs a [deprecated_at] date.', $name));
        }

        if ($status === self::SUNSET_ANNOUNCED && $sunsetAt === null) {
            throw new InvalidArgumentException(sprintf('API version [%s] with an announced sunset needs a [sunset_at] date.', $name));
        }

        if ($link !== null && (! is_string($link) || filter_var($link, FILTER_VALIDATE_URL) === false || preg_match('/[\s<>"]/', $link) === 1)) {
            throw new InvalidArgumentException(sprintf('The lifecycle [link] of API version [%s] must be an absolute URL.', $name));
        }

        return ['status' => $status, 'deprecated_at' => $deprecatedAt, 'sunset_at' => $sunsetAt, 'link' => $link];
    }

    private static function timestamp(string $name, string $key, mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(sprintf('The lifecycle [%s] of API version [%s] must be a date string.', $key, $name));
        }

        try {
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->getTimestamp();
        } catch (Exception $e) {
            throw new InvalidArgumentException(sprintf('The lifecycle [%s] of API version [%s] is not a valid date.', $key, $name), 0, $e);
        }
    }
}
