<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An hourly rate (docs/specs/05-hr-payroll.md 5.5, fixing C6). Scoped to
 * exactly one of staff contract / salary grade / class level (XOR CHECK);
 * resolution precedence is staff -> grade -> class_level, most specific
 * wins, and a tie is a configuration error rejected at save.
 *
 * @property int $id
 * @property string $scope
 * @property int|null $staff_contract_id
 * @property int|null $salary_grade_id
 * @property int|null $class_level_id
 * @property int|null $subject_id
 * @property int $rate_per_hour
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class HourlyRate extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'scope',
        'staff_contract_id',
        'salary_grade_id',
        'class_level_id',
        'subject_id',
        'rate_per_hour',
        'effective_from',
        'effective_to',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rate_per_hour' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }
}
