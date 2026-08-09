<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * docs/specs/06-assets-stores.md §7.3 - PCS / BOX / KG / L.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $name_fr
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class UnitOfMeasure extends Model
{
    protected $table = 'units_of_measure';

    /** @var list<string> */
    protected $fillable = ['code', 'name', 'name_fr', 'is_active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
