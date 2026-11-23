<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Routing\Route;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Middleware\Tag;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Models\Widget;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Support\Greeter;

final class UserV1Controller extends Controller
{
    public function __construct(private Greeter $greeter)
    {
        $this->middleware(Tag::class.':v1');
    }

    /**
     * @return array<string, mixed>
     */
    public function index(Request $request): array
    {
        $route = $request->route();

        return [
            'controller' => 'v1',
            'greeting' => $this->greeter->greet('v1'),
            'parameters' => $route instanceof Route ? array_keys($route->parameters()) : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function show(Widget $widget): array
    {
        return ['controller' => 'v1', 'widget' => $widget->id, 'name' => $widget->name];
    }
}
