<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Controllers;

use Illuminate\Routing\Controller;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Middleware\Tag;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Models\Widget;

/**
 * The route's own controller: used only when a route falls back to it.
 */
final class UserController extends Controller
{
    public function __construct()
    {
        $this->middleware(Tag::class.':default');
    }

    /**
     * @return array<string, string>
     */
    public function index(): array
    {
        return ['controller' => 'default'];
    }

    /**
     * @return array<string, mixed>
     */
    public function show(Widget $widget): array
    {
        return ['controller' => 'default', 'widget' => $widget->id];
    }
}
