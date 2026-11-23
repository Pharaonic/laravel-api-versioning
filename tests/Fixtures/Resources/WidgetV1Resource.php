<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Models\Widget;

/**
 * @property Widget $resource
 */
final class WidgetV1Resource extends JsonResource
{
    /**
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return ['id' => $this->resource->id, 'title' => $this->resource->name];
    }
}
