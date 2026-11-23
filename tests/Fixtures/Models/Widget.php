<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiVersioning\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $email
 * @property int|null $widget_id
 */
final class Widget extends Model
{
    /** @var array<int, string> */
    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = ['id' => 'integer', 'widget_id' => 'integer'];

    public $timestamps = false;
}
