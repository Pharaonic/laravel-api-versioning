<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Middleware\Tag;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Models\Widget;

final class UserV2Controller extends Controller
{
    public function __construct()
    {
        $this->middleware(Tag::class.':v2');
        $this->middleware(Tag::class.':v2-show-only')->only('show');
    }

    /**
     * @return array<string, string>
     */
    public function index(): array
    {
        return ['controller' => 'v2'];
    }

    /**
     * @return array<string, mixed>
     */
    public function show(Widget $widget): array
    {
        Gate::authorize('view-widget', $widget);

        return ['controller' => 'v2', 'widget' => $widget->id, 'email' => $widget->email];
    }
}
