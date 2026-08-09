<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Modules\HR\Domain\LeavePayer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Leave reference data (docs/specs/05-hr-payroll.md 12.2), seeded WITHOUT
 * `statutory_days` and WITHOUT `monthly_accrual_days`: statutory figures are
 * 2.3 reference values, never seed data (0). AccrueMonthlyLeave refuses
 * while the accrual rate is NULL.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $name_fr
 * @property bool $is_paid
 * @property LeavePayer $payer
 * @property bool $accrues_leave
 * @property bool $counts_as_effective_service
 * @property int|null $statutory_days
 * @property numeric-string|null $monthly_accrual_days
 * @property bool $requires_medical_certificate
 * @property int|null $max_consecutive_days
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class LeaveType extends Model
{
    /** The seeded annual-leave code the monthly accrual targets (12.3). */
    public const ANNUAL_CODE = 'conge_annuel';

    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'name_fr',
        'is_paid',
        'payer',
        'accrues_leave',
        'counts_as_effective_service',
        'statutory_days',
        'monthly_accrual_days',
        'requires_medical_certificate',
        'max_consecutive_days',
        'is_active',
    ];

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'is_paid' => 'boolean',
            'payer' => LeavePayer::class,
            'accrues_leave' => 'boolean',
            'counts_as_effective_service' => 'boolean',
            'statutory_days' => 'integer',
            'monthly_accrual_days' => 'decimal:2',
            'requires_medical_certificate' => 'boolean',
            'max_consecutive_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
