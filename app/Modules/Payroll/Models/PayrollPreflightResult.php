<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Modules\Payroll\Domain\PreflightCheckCode;
use App\Modules\Payroll\Domain\PreflightStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One persisted preflight check row (docs/specs/05-hr-payroll.md 9.1): the
 * bursar sees a checklist, not a stack trace, and each failing row links
 * to the settings screen that fixes it.
 *
 * @property int $id
 * @property int $payroll_run_id
 * @property PreflightCheckCode $check_code
 * @property PreflightStatus $status
 * @property array<string, mixed> $detail
 * @property Carbon $checked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class PayrollPreflightResult extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'payroll_run_id',
        'check_code',
        'status',
        'detail',
        'checked_at',
    ];

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'check_code' => PreflightCheckCode::class,
            'status' => PreflightStatus::class,
            'detail' => 'array',
            'checked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PayrollRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }
}
