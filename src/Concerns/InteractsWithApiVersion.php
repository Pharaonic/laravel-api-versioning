<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiVersioning\Concerns;

use Pharaonic\Laravel\ApiVersioning\Support\VersionExpression;

/**
 * Adds whenVersion() to a JsonResource. From Laravel 9, JsonResource is
 * macroable and the package registers whenVersion() as a macro, so the trait
 * is only needed on Laravel 6 to 8; using it on later versions is harmless.
 */
trait InteractsWithApiVersion
{
    /**
     * The value when the request's API version matches the expression
     * ("v2", ">=v2", ">v1", "<=v2", "<v3"), otherwise the default, or a
     * removed attribute when no default is given. A closure value is only
     * called when the attribute is included.
     */
    protected function whenVersion(string $expression, mixed $value, mixed $default = null): mixed
    {
        return VersionExpression::when($expression, $value, $default, func_num_args() >= 3);
    }
}
