<?php

declare(strict_types=1);

namespace App\Modules\Tax\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * docs/specs/03-tax-procurement.md §7.1 - one box of a declaration.
 * `line_code` stays an INTERNAL code until the official DGI form boxes
 * are verified and configured on the type (`form_boxes`); the supplier_*
 * columns are the §7.3 per-supplier annex snapshot (name and NIU at the
 * time - required by the form and impossible to reconstruct later).
 *
 * @property int $id
 * @property int $tax_declaration_id
 * @property int $line_no
 * @property string $line_code
 * @property string $label
 * @property int $base_amount
 * @property int|null $rate_bp
 * @property int $tax_amount
 * @property bool $is_late_claim
 * @property string $source
 * @property string|null $manual_reason
 * @property int|null $supplier_id
 * @property string|null $supplier_name
 * @property string|null $supplier_niu
 */
final class TaxDeclarationLine extends Model
{
    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'base_amount' => 'integer',
            'rate_bp' => 'integer',
            'tax_amount' => 'integer',
            'is_late_claim' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<TaxDeclaration, $this>
     */
    public function declaration(): BelongsTo
    {
        return $this->belongsTo(TaxDeclaration::class, 'tax_declaration_id');
    }
}
