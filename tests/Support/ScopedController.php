<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiVersioning\Tests\Support;

use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Models\Widget;

final class ScopedController
{
    /**
     * @return array<string, mixed>
     */
    public function part(ParentWidget $widget, Widget $part): array
    {
        return ['controller' => 'v2', 'widget' => $widget->getKey(), 'part' => $part->id];
    }
}
