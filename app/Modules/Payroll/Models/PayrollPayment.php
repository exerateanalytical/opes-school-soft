<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Modules\Payroll\Domain\PayrollPaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The operational trigger v1 never had for "on payment: clear the payables"
 * (docs/specs/05-hr-payroll.md 8.8). One payment covers the net pay of an
 * APPROVED run; the ledger write happens at export, through PostFromEvent
 * ('payroll.paid') and nowhere else.
 *
 * @property int $id
 * @property int $payroll_run_id
 * @property string $payment_method
 * @property int $treasury_account_id
 * @property Carbon $value_date
 * @property int $total_amount
 * @property int|null $disbursement_file_id
 * @property PayrollPaymentStatus $status
 * @property int|null $exported_by
 * @property Carbon|null $exported_at
 * @property int|null $journal_entry_id
 * @property string $idempotency_key
 * @property int $created_by
 * @property int $version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class PayrollPayment extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'payroll_run_id',
        'payment_method',
        'treasury_account_id',
        'value_date',
        'total_amount',
        'disbursement_file_id',
        'status',
        'exported_by',
        'exported_at',
        'journal_entry_id',
        'idempotency_key',
        'created_by',
    ];

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'value_date' => 'date',
            'total_amount' => 'integer',
            'status' => PayrollPaymentStatus::class,
            'exported_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<PayrollRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    /**
     * @return HasMany<PayrollPaymentLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PayrollPaymentLine::class, 'payroll_payment_id');
    }
}
