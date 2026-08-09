<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Effective-dated compensation history (docs/specs/05-hr-payroll.md 5.1,
 * fixing C7): a raise is a NEW row, never an edit - a March raise must not
 * change a January payslip. `retroactive_from` earlier than
 * `effective_from` makes the calculate Action generate ARREARS lines for
 * the intervening approved months, computed against their snapshots.
 *
 * `staff_contract_id` references HR's table at the schema level only; all
 * cross-module reads stay DB::table per the module boundary.
 *
 * @property int $id
 * @property int $staff_contract_id
 * @property string $component_code
 * @property int|null $amount
 * @property int|null $rate_bp
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 * @property Carbon|null $retroactive_from
 * @property int $granted_by
 * @property string $grant_reason
 * @property int|null $document_id
 * @property int $version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class StaffCompensation extends Model
{
    // Laravel treats "compensation" as uncountable and would otherwise
    // guess `staff_compensation`; the table (290009) is plural.
    protected $table = 'staff_compensations';

    /** @var list<string> */
    protected $fillable = [
        'staff_contract_id',
        'component_code',
        'amount',
        'rate_bp',
        'effective_from',
        'effective_to',
        'retroactive_from',
        'granted_by',
        'grant_reason',
        'document_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'rate_bp' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'retroactive_from' => 'date',
            'version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<PayrollComponent, $this>
     */
    public function component(): BelongsTo
    {
        return $this->belongsTo(PayrollComponent::class, 'component_code', 'code');
    }
}
