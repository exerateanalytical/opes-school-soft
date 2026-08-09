<?php

declare(strict_types=1);

namespace App\Modules\Tax\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * docs/specs/03-tax-procurement.md §7.1 - declaration types as an
 * EXTENSIBLE reference table, not an enum: the full list is not verified.
 * Archive-flag, never delete - a retired type stays referencable by
 * historical declarations.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $name_fr
 * @property string $period_type
 * @property bool $is_archived
 */
final class TaxDeclarationType extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'name_fr',
        'period_type',
        'is_archived',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_archived' => 'boolean',
        ];
    }
}
