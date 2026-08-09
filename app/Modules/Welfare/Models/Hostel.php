<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Models;

use App\Modules\Welfare\Domain\HostelGender;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * docs/plans/phase-10.md §3 row 5. Welfare models relate to each other
 * only; enrollment/student data crosses the boundary via DB::table inside
 * Actions (ModuleBoundaryTest). The warden is referenced by bare user id
 * for the same reason.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property HostelGender $gender
 * @property int|null $warden_user_id
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Hostel extends Model
{
    /** @var list<string> */
    protected $fillable = ['code', 'name', 'gender', 'warden_user_id', 'is_active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'gender' => HostelGender::class,
            'warden_user_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<HostelRoom, $this>
     */
    public function rooms(): HasMany
    {
        return $this->hasMany(HostelRoom::class, 'hostel_id')->orderBy('name');
    }

    /**
     * @return HasMany<HostelInspection, $this>
     */
    public function inspections(): HasMany
    {
        return $this->hasMany(HostelInspection::class, 'hostel_id');
    }
}
