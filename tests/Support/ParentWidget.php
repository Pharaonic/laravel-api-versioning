<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiVersioning\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Models\Widget;

/**
 * A widget with child widgets, for scoped bindings. Kept out of the analysed
 * fixtures: relation generics differ between Laravel lines.
 */
final class ParentWidget extends Model
{
    protected $table = 'widgets';

    public $timestamps = false;

    public function parts(): HasMany
    {
        return $this->hasMany(Widget::class, 'widget_id');
    }
}
