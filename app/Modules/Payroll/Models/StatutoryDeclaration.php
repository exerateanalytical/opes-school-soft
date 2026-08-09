<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Modules\Payroll\Domain\DeclarationStatus;
use App\Modules\Payroll\Domain\DeclarationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One statutory return (docs/specs/05-hr-payroll.md 11.1). Where the
 * deadline is NEEDS VERIFICATION, `due_date` is NULL and the UI shows
 * "Deadline not configured" - a fabricated deadline produces false
 * confidence, which is worse than none.
 *
 * @property int $id
 * @property DeclarationType $type
 * @property string $payee
 * @property Carbon|null $period_month
 * @property int|null $period_year
 * @property int|null $staff_member_id
 * @property Carbon|null $due_date
 * @property DeclarationStatus $status
 * @property Carbon|null $generated_at
 * @property Carbon|null $filed_at
 * @property Carbon|null $paid_at
 * @property string|null $external_reference
 * @property int|null $amount_declared
 * @property int|null $amount_paid
 * @property int $penalty_amount
 * @property array<int, int>|null $generated_from_run_ids
 * @property int|null $export_document_id
 * @property int|null $filed_by
 * @property string|null $dedupe_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class StatutoryDeclaration extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'type',
        'payee',
        'period_month',
        'period_year',
        'staff_member_id',
        'due_date',
        'status',
        'generated_at',
        'filed_at',
        'paid_at',
        'external_reference',
        'amount_declared',
        'amount_paid',
        'penalty_amount',
        'generated_from_run_ids',
        'export_document_id',
        'filed_by',
    ];

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'type' => DeclarationType::class,
            'period_month' => 'date',
            'period_year' => 'integer',
            'due_date' => 'date',
            'status' => DeclarationStatus::class,
            'generated_at' => 'datetime',
            'filed_at' => 'datetime',
            'paid_at' => 'datetime',
            'amount_declared' => 'integer',
            'amount_paid' => 'integer',
            'penalty_amount' => 'integer',
            'generated_from_run_ids' => 'array',
        ];
    }
}
