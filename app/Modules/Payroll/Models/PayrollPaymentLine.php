<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Modules\Payroll\Domain\PaymentLineStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One staff member's net-pay leg of a disbursement (docs/specs/05-hr-payroll.md
 * 8.8). `beneficiary_account` is ciphertext copied at prepare time and
 * decrypted AT EXPORT ONLY via the `encrypted` cast; the generated
 * `live_item_key` UNIQUE makes double disbursement of a payroll item
 * structurally impossible - only a `failed` line releases its item.
 *
 * @property int $id
 * @property int $payroll_payment_id
 * @property int $payroll_item_id
 * @property int $staff_member_id
 * @property int $amount
 * @property string|null $beneficiary_account
 * @property PaymentLineStatus $status
 * @property string|null $failure_reason
 * @property int|null $live_item_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class PayrollPaymentLine extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'payroll_payment_id',
        'payroll_item_id',
        'staff_member_id',
        'amount',
        'beneficiary_account',
        'status',
        'failure_reason',
    ];

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'beneficiary_account' => 'encrypted',
            'status' => PaymentLineStatus::class,
        ];
    }

    /**
     * @return BelongsTo<PayrollPayment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(PayrollPayment::class, 'payroll_payment_id');
    }
}
