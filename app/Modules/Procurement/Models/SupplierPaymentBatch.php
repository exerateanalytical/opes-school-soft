<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * docs/specs/03-tax-procurement.md §4.7 - a disbursement file: approved
 * payments grouped into a bank transfer or MoMo bulk file. No specific
 * bank layout is specified (NEEDS VERIFICATION per bank); `export_format`
 * names the generic format and `file_hash` fingerprints what was exported.
 *
 * @property int $id
 * @property string $batch_no
 * @property int $bank_account_id
 * @property string $export_format
 * @property int $payment_count
 * @property int $total_amount
 * @property string|null $file_hash
 * @property Carbon|null $exported_at
 * @property int|null $exported_by
 * @property int $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class SupplierPaymentBatch extends Model
{
    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payment_count' => 'integer',
            'total_amount' => 'integer',
            'exported_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<SupplierPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class, 'batch_id');
    }
}
