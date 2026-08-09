<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A stored formula unit test (docs/specs/05-hr-payroll.md 5.4): a named
 * input vector -> expected integer output, executed at save and re-executed
 * by preflight check 7. A formula with no passing test cannot be enabled -
 * the test row is what makes a formula reviewable by someone other than
 * its author.
 *
 * @property int $id
 * @property int $payroll_component_id
 * @property string $name
 * @property array<string, int> $inputs
 * @property int $expected
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class PayrollComponentTest extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'payroll_component_id',
        'name',
        'inputs',
        'expected',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'inputs' => 'array',
            'expected' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<PayrollComponent, $this>
     */
    public function component(): BelongsTo
    {
        return $this->belongsTo(PayrollComponent::class, 'payroll_component_id');
    }
}
