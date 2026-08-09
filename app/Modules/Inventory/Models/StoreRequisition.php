<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Domain\StoreRequisitionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * docs/specs/06-assets-stores.md §7.8 - the internal-consumption analogue
 * of a purchase requisition; what makes the analytic split defensible.
 *
 * @property int $id
 * @property string $requisition_no
 * @property int|null $school_section_id
 * @property string|null $department
 * @property int $requested_by
 * @property int|null $approved_by
 * @property StoreRequisitionStatus $status
 * @property Carbon|null $needed_on
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class StoreRequisition extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'requisition_no', 'school_section_id', 'department',
        'requested_by', 'approved_by', 'status', 'needed_on', 'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => StoreRequisitionStatus::class,
            'needed_on' => 'date',
        ];
    }

    /**
     * @return HasMany<StoreRequisitionLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(StoreRequisitionLine::class);
    }
}
