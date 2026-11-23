<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Pharaonic\Laravel\ApiVersioning\Version;

final class ProfileController
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(Request $request): array
    {
        $version = $request->attributes->get('pharaonic.api-versioning');
        $route = $request->route();

        return [
            'controller' => 'profile',
            'version' => $version instanceof Version ? $version->name() : null,
            'parameters' => $route instanceof Route ? array_keys($route->parameters()) : [],
        ];
    }
}
