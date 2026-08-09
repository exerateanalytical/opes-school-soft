<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * docs/specs/03-tax-procurement.md §3.4 - reference data. Archive-flag
 * deletion (§9): never SoftDeletes, the unique `code` would be blocked.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $name_fr
 * @property int|null $default_expense_account_id
 * @property int|null $default_tax_code_id
 * @property int|null $default_withholding_profile_id
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class SupplierCategory extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'name_fr',
        'default_expense_account_id',
        'default_tax_code_id',
        'default_withholding_profile_id',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Supplier, $this>
     */
    public function suppliers(): HasMany
    {
        return $this->hasMany(Supplier::class, 'category_id');
    }
}
