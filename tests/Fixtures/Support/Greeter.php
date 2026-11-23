<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Support;

/**
 * A service injected into controller constructors.
 */
final class Greeter
{
    public function greet(string $name): string
    {
        return 'Hello '.$name;
    }
}
