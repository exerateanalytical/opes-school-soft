<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * docs/plans/phase-10.md §3 row 1. Welfare models relate to each other
 * only; enrollment/student data crosses the boundary via DB::table inside
 * Actions (ModuleBoundaryTest).
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class TransportRoute extends Model
{
    /** @var list<string> */
    protected $fillable = ['code', 'name', 'description', 'is_active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<TransportStop, $this>
     */
    public function stops(): HasMany
    {
        return $this->hasMany(TransportStop::class, 'route_id')->orderBy('sequence');
    }

    /**
     * @return HasMany<TransportAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(TransportAllocation::class, 'route_id');
    }
}
