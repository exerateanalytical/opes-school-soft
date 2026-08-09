<?php

declare(strict_types=1);

namespace App\Modules\Library\Models;

use App\Modules\Library\Domain\FineCapPolicy;
use Database\Factories\MembershipClassFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * 06-assets-stores.md §10.3 - borrowing limits and fine terms as DATA.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $name_fr
 * @property int $max_concurrent_issues
 * @property int $loan_days
 * @property int $max_renewals
 * @property int $renewal_days
 * @property int $fine_per_day
 * @property int $fine_grace_days
 * @property FineCapPolicy $fine_cap_policy
 * @property int $blocking_fine_threshold
 * @property int $max_reservations
 * @property bool $can_borrow_reference
 * @property bool $is_archived
 */
final class MembershipClass extends Model
{
    /** @use HasFactory<MembershipClassFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'code', 'name', 'name_fr', 'max_concurrent_issues', 'loan_days',
        'max_renewals', 'renewal_days', 'fine_per_day', 'fine_grace_days',
        'fine_cap_policy', 'blocking_fine_threshold', 'max_reservations',
        'can_borrow_reference', 'is_archived',
    ];

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'max_concurrent_issues' => 'integer',
            'loan_days' => 'integer',
            'max_renewals' => 'integer',
            'renewal_days' => 'integer',
            'fine_per_day' => 'integer',
            'fine_grace_days' => 'integer',
            'fine_cap_policy' => FineCapPolicy::class,
            'blocking_fine_threshold' => 'integer',
            'max_reservations' => 'integer',
            'can_borrow_reference' => 'boolean',
            'is_archived' => 'boolean',
        ];
    }
}
