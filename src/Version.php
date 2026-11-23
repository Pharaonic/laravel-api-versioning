<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiVersioning;

/**
 * One API version, compared by its position in the configured version list,
 * never by its name.
 */
final class Version
{
    /**
     * @internal Versions come from VersionRegistry::get() or Request::apiVersion().
     */
    public function __construct(
        private string $name,
        private VersionRegistry $registry,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function is(Version|string $version): bool
    {
        return $this->registry->compare($this, $version) === 0;
    }

    public function atLeast(Version|string $version): bool
    {
        return $this->registry->compare($this, $version) >= 0;
    }

    public function atMost(Version|string $version): bool
    {
        return $this->registry->compare($this, $version) <= 0;
    }

    public function after(Version|string $version): bool
    {
        return $this->registry->compare($this, $version) > 0;
    }

    public function before(Version|string $version): bool
    {
        return $this->registry->compare($this, $version) < 0;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
