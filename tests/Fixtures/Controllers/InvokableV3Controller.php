<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Controllers;

final class InvokableV3Controller
{
    /**
     * @return array<string, string>
     */
    public function __invoke(): array
    {
        return ['controller' => 'v3'];
    }
}
