<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Models;

use App\Modules\Welfare\Domain\AllocationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A boarder's bed, keyed on enrollment_id (07-students line 39). TWO
 * schema-enforced invariants via NULL-unique generated columns: at most
 * one active row per enrollment AND at most one active row per bed, so
 * neither a double bed nor a double boarder can exist on any code path.
 * The generated keys are intentionally NOT fillable: MySQL computes them.
 *
 * @property int $id
 * @property int $enrollment_id
 * @property int $bed_id
 * @property Carbon $starts_on
 * @property Carbon|null $ends_on
 * @property AllocationStatus $status
 * @property int|null $allocated_by
 * @property int|null $active_enrollment_key
 * @property int|null $active_bed_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class HostelAllocation extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'enrollment_id', 'bed_id', 'starts_on', 'ends_on', 'status', 'allocated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enrollment_id' => 'integer',
            'bed_id' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'status' => AllocationStatus::class,
            'allocated_by' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<HostelBed, $this>
     */
    public function bed(): BelongsTo
    {
        return $this->belongsTo(HostelBed::class, 'bed_id');
    }

    /**
     * @param  Builder<HostelAllocation>  $query
     * @return Builder<HostelAllocation>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', '=', AllocationStatus::Active->value);
    }
}
