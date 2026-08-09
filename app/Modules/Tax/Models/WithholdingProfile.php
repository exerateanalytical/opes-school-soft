<?php

declare(strict_types=1);

namespace App\Modules\Tax\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * docs/specs/03-tax-procurement.md §6.2 - a named, ordered grouping of
 * withholding rules for assignment to a supplier. A supplier with no
 * profile resolves dynamically through ResolveWithholding (§6.4).
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $name_fr
 * @property bool $is_active
 */
final class WithholdingProfile extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'name_fr',
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
     * @return HasMany<WithholdingProfileRule, $this>
     */
    public function profileRules(): HasMany
    {
        return $this->hasMany(WithholdingProfileRule::class)->orderBy('sequence');
    }
}
