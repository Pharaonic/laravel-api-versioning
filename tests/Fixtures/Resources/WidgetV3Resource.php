<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Models\Widget;

/**
 * The v3 structure: the name moved into a nested object.
 *
 * @property Widget $resource
 */
final class WidgetV3Resource extends JsonResource
{
    /**
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->resource->id,
            'profile' => ['name' => $this->resource->name],
        ];
    }
}
