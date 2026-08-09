<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use Database\Factories\SalaryGradeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A classification grade (docs/specs/05-hr-payroll.md 3.4). Deliberately
 * carries NO salary amount: pay is a HISTORY on `staff_compensations` (5.1),
 * and any scale seeded here would be an unverified statutory table (2.4 -
 * the applicable convention collective is NEEDS VERIFICATION).
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $name_fr
 * @property string|null $category
 * @property string|null $echelon
 * @property int|null $collective_agreement_id
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class SalaryGrade extends Model
{
    /** @use HasFactory<SalaryGradeFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'name_fr',
        'category',
        'echelon',
        'collective_agreement_id',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'collective_agreement_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function newFactory(): SalaryGradeFactory
    {
        return SalaryGradeFactory::new();
    }
}
