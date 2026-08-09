<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Models;

use App\Modules\Procurement\Domain\RequisitionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * docs/specs/03-tax-procurement.md §4.1.
 *
 * The DB's BEFORE DELETE trigger is the real §9 draft-only-delete guard
 * (it also covers raw DB::table paths); the observer repeats it so the
 * Eloquent path fails with a legible message instead of a SQLSTATE 45000.
 *
 * @property int $id
 * @property string $requisition_no
 * @property int $requested_by
 * @property int|null $department_id
 * @property int|null $school_section_id
 * @property string $requested_on
 * @property string|null $needed_by
 * @property string|null $justification
 * @property RequisitionStatus $status
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property string|null $rejected_reason
 * @property int|null $budget_line_id
 * @property int $estimated_total
 * @property int $academic_year_id
 * @property int $fiscal_year_id
 * @property string|null $idempotency_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class PurchaseRequisition extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'requisition_no',
        'requested_by',
        'department_id',
        'school_section_id',
        'requested_on',
        'needed_by',
        'justification',
        'status',
        'approved_by',
        'approved_at',
        'rejected_reason',
        'budget_line_id',
        'estimated_total',
        'academic_year_id',
        'fiscal_year_id',
        'idempotency_key',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RequisitionStatus::class,
            'approved_at' => 'datetime',
            'estimated_total' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (PurchaseRequisition $requisition): void {
            if ($requisition->status !== RequisitionStatus::Draft) {
                throw new \RuntimeException(sprintf(
                    'Requisition %s has left draft and can only be cancelled, never deleted (03-tax-procurement 9).',
                    $requisition->requisition_no,
                ));
            }
        });
    }

    /**
     * @return HasMany<PurchaseRequisitionLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseRequisitionLine::class, 'requisition_id')->orderBy('line_no');
    }
}
