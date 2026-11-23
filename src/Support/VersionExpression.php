<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiVersioning\Support;

use Illuminate\Container\Container;
use Illuminate\Http\Resources\MissingValue;
use InvalidArgumentException;
use Pharaonic\Laravel\ApiVersioning\Version;
use Pharaonic\Laravel\ApiVersioning\VersionRegistry;

/**
 * Version expressions of whenVersion(): "v2" or "=v2" (exactly), ">=v2",
 * ">v2", "<=v2" and "<v2", compared by declared order. Parsed with one
 * regular expression and a fixed operator table; nothing is evaluated.
 *
 * @internal
 */
final class VersionExpression
{
    private const PATTERN = '/^\s*(>=|<=|>|<|=)?\s*([A-Za-z0-9][A-Za-z0-9._-]*)\s*$/';

    /**
     * Whether the version of the current request matches the expression.
     * A request without a resolved version matches nothing.
     */
    public static function matches(string $expression): bool
    {
        /** @var VersionRegistry $registry */
        $registry = Container::getInstance()->make(VersionRegistry::class);

        [$operator, $target] = self::parse($expression, $registry);
        $version = CurrentVersion::of();

        return $version !== null && self::compare($version, $operator, $target);
    }

    /**
     * The value of whenVersion(): the value when the expression matches,
     * otherwise the default when one was given, otherwise Laravel's
     * MissingValue, so the attribute is removed from the resource.
     */
    public static function when(string $expression, mixed $value, mixed $default, bool $hasDefault): mixed
    {
        if (self::matches($expression)) {
            return value($value);
        }

        return $hasDefault ? value($default) : new MissingValue;
    }

    /**
     * @return array{string, string}
     */
    private static function parse(string $expression, VersionRegistry $registry): array
    {
        if (preg_match(self::PATTERN, $expression, $matches) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Invalid API version expression [%s]; use a version name, optionally prefixed with =, >=, >, <= or <.', $expression,
            ));
        }

        return [$matches[1] === '' ? '=' : $matches[1], $registry->get($matches[2])->name()];
    }

    private static function compare(Version $version, string $operator, string $target): bool
    {
        return match ($operator) {
            '>=' => $version->atLeast($target),
            '>' => $version->after($target),
            '<=' => $version->atMost($target),
            '<' => $version->before($target),
            default => $version->is($target),
        };
    }
}
