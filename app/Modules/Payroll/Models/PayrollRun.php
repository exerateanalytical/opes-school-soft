<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Modules\Payroll\Domain\RunStatus;
use App\Modules\Payroll\Domain\RunType;
use Database\Factories\PayrollRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use App\Support\Retention\Immutable10Year;

/**
 * A payroll run (docs/specs/05-hr-payroll.md 8) - EMPLOYER-WIDE, once per
 * month (H3). Every lifecycle transition is a conditional UPDATE with an
 * affected-rows check (00-core 10.4), executed by the Actions - this model
 * carries no transition logic on purpose.
 *
 * @property int $id
 * @property Carbon $payroll_month
 * @property RunType $run_type
 * @property RunStatus $status
 * @property int $fiscal_year_id
 * @property int $academic_year_id
 * @property int $accounting_period_id
 * @property int $employer_profile_id
 * @property string|null $inputs_hash
 * @property int|null $calculated_by
 * @property Carbon|null $calculated_at
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property Carbon|null $paid_at
 * @property Carbon|null $closed_at
 * @property int|null $cancelled_by
 * @property Carbon|null $cancelled_at
 * @property string|null $cancellation_reason
 * @property int|null $reverses_run_id
 * @property int|null $journal_entry_id
 * @property string|null $idempotency_key
 * @property int $version
 * @property bool|null $active_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class PayrollRun extends Model
{
    use Immutable10Year;
    /** @use HasFactory<PayrollRunFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'payroll_month',
        'run_type',
        'status',
        'fiscal_year_id',
        'academic_year_id',
        'accounting_period_id',
        'employer_profile_id',
        'inputs_hash',
        'calculated_by',
        'calculated_at',
        'approved_by',
        'approved_at',
        'paid_at',
        'closed_at',
        'cancelled_by',
        'cancelled_at',
        'cancellation_reason',
        'reverses_run_id',
        'journal_entry_id',
        'idempotency_key',
        'version',
    ];

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'payroll_month' => 'date',
            'run_type' => RunType::class,
            'status' => RunStatus::class,
            'calculated_at' => 'datetime',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
            'closed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'active_key' => 'boolean',
        ];
    }

    /**
     * @return HasMany<PayrollItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PayrollItem::class);
    }

    /**
     * @return HasMany<PayrollPreflightResult, $this>
     */
    public function preflightResults(): HasMany
    {
        return $this->hasMany(PayrollPreflightResult::class);
    }

    /**
     * @return BelongsTo<EmployerProfile, $this>
     */
    public function employerProfile(): BelongsTo
    {
        return $this->belongsTo(EmployerProfile::class);
    }

    /**
     * @return BelongsTo<PayrollRun, $this>
     */
    public function reversedRun(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_run_id');
    }

    /** The payroll period END date - what drives every rate resolution (4.3). */
    public function periodEnd(): Carbon
    {
        return $this->payroll_month->copy()->endOfMonth()->startOfDay();
    }

    protected static function newFactory(): PayrollRunFactory
    {
        return PayrollRunFactory::new();
    }
}
