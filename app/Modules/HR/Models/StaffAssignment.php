<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * staff_contract x class_group x subject x academic_year
 * (docs/specs/05-hr-payroll.md 3.7). Gates marks entry in the Action layer.
 *
 * Keyed on the CONTRACT, not the person: a teacher who converts mid-year
 * keeps their history on the old contract. `class_group_id`, `subject_id`
 * and `academic_year_id` are plain FK attributes - those tables belong to
 * Academics and are read cross-module via DB::table only.
 *
 * @property int $id
 * @property int $staff_contract_id
 * @property int $class_group_id
 * @property int $subject_id
 * @property int $academic_year_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class StaffAssignment extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'staff_contract_id',
        'class_group_id',
        'subject_id',
        'academic_year_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'class_group_id' => 'integer',
            'subject_id' => 'integer',
            'academic_year_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<StaffContract, $this>
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(StaffContract::class, 'staff_contract_id');
    }
}
