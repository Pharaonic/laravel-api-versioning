<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;
use Pharaonic\Laravel\ApiVersioning\Concerns\InteractsWithApiVersion;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Models\Widget;

/**
 * @property Widget $resource
 */
class WidgetResource extends JsonResource
{
    use InteractsWithApiVersion;

    /**
     * @param  \Illuminate\Http\Request  $request
     * @return array<int|string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'email' => $this->whenVersion('>=v2', fn () => $this->resource->email),
            'legacy_name' => $this->whenVersion('<v2', fn () => $this->resource->name),
            'only_v1' => $this->whenVersion('v1', function (): string {
                if (! CurrentVersionProbe::isV1()) {
                    throw new LogicException('An excluded whenVersion() closure was evaluated.');
                }

                return 'yes';
            }),
            'tier' => $this->whenVersion('>v2', 'premium', 'basic'),
            'parent' => $this->when($this->resource->widget_id !== null, fn () => $this->resource->widget_id),
            'children' => self::collection($this->whenLoaded('children')),
            $this->mergeWhen(true, ['merged' => true]),
        ];
    }
}
