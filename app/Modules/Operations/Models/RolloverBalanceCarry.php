<?php

declare(strict_types=1);

namespace App\Modules\Operations\Models;

use Database\Factories\RolloverBalanceCarryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The per-student step-7 outcome (docs/specs/08-operations.md §6.2): a credit
 * carried forward, a debt explicitly carried as opening debt, a write-off, or
 * an enrolment block. Ledger-touching kinds reference the journal entry
 * PostFromEvent created - this table never posts anything itself.
 *
 * `kind` stays a plain string: the four values are this table's private
 * vocabulary (constants below), not a domain concept other modules consume.
 *
 * @property int $id
 * @property int $rollover_run_id
 * @property int $student_id
 * @property string $kind
 * @property int $amount
 * @property int|null $journal_entry_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class RolloverBalanceCarry extends Model
{
    /** @use HasFactory<RolloverBalanceCarryFactory> */
    use HasFactory;

    public const KIND_CREDIT_CARRY = 'credit_carry';

    public const KIND_DEBT_CARRY = 'debt_carry';

    public const KIND_WRITE_OFF = 'write_off';

    public const KIND_BLOCK = 'block';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rollover_run_id' => 'integer',
            'student_id' => 'integer',
            'amount' => 'integer',
            'journal_entry_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<RolloverRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(RolloverRun::class, 'rollover_run_id');
    }

    protected static function newFactory(): RolloverBalanceCarryFactory
    {
        return RolloverBalanceCarryFactory::new();
    }
}
