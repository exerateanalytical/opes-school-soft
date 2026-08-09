<?php

declare(strict_types=1);

namespace App\Modules\Library\Models;

use App\Modules\Library\Domain\FineStatus;
use App\Modules\Library\Domain\FineType;
use App\Modules\Library\Domain\SettlementRoute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 06-assets-stores.md §10.5-§10.7 - the ASSESSMENT record. The student
 * DEBT lives in Fees through `invoice_id` (single debt stream); staff
 * fines queue for payroll (Phase 11); the library never keeps its own
 * paid/unpaid flag.
 *
 * @property int $id
 * @property string $fine_no
 * @property int|null $library_issue_id
 * @property int $library_member_id
 * @property int|null $student_id
 * @property FineType $fine_type
 * @property string $assessed_on
 * @property int|null $days_overdue
 * @property int $amount
 * @property int $waived_amount
 * @property int|null $waived_by
 * @property string|null $waived_reason
 * @property int|null $levied_by
 * @property FineStatus $status
 * @property int|null $invoice_id
 * @property int|null $credit_note_id
 * @property int|null $payroll_deduction_id
 * @property int|null $journal_entry_id
 * @property SettlementRoute $settlement_route
 * @property string|null $idempotency_key
 */
final class LibraryFine extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'fine_no', 'library_issue_id', 'library_member_id', 'student_id',
        'fine_type', 'assessed_on', 'days_overdue', 'amount', 'waived_amount',
        'waived_by', 'waived_reason', 'levied_by', 'status', 'invoice_id',
        'credit_note_id', 'payroll_deduction_id', 'journal_entry_id',
        'settlement_route', 'idempotency_key',
    ];

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'library_issue_id' => 'integer',
            'library_member_id' => 'integer',
            'student_id' => 'integer',
            'fine_type' => FineType::class,
            'days_overdue' => 'integer',
            'amount' => 'integer',
            'waived_amount' => 'integer',
            'waived_by' => 'integer',
            'levied_by' => 'integer',
            'status' => FineStatus::class,
            'invoice_id' => 'integer',
            'credit_note_id' => 'integer',
            'payroll_deduction_id' => 'integer',
            'journal_entry_id' => 'integer',
            'settlement_route' => SettlementRoute::class,
        ];
    }

    /**
     * @return BelongsTo<LibraryIssue, $this>
     */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(LibraryIssue::class, 'library_issue_id');
    }

    /**
     * @return BelongsTo<LibraryMember, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(LibraryMember::class, 'library_member_id');
    }
}
