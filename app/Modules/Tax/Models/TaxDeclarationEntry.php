<?php

declare(strict_types=1);

namespace App\Modules\Tax\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * docs/specs/03-tax-procurement.md §7.1 - the normalised pivot from a
 * declaration to the JournalEntryLines it was generated from. The JSON on
 * the header is for human inspection; THIS is what queries, reconciles,
 * and re-verifies the inputs_hash at filing. No Eloquent relation to the
 * Accounting models: cross-module reads go through DB::table (00-core
 * §6.2), so the ids stay plain integers here.
 *
 * @property int $id
 * @property int $tax_declaration_id
 * @property int $journal_entry_id
 * @property int $journal_entry_line_id
 */
final class TaxDeclarationEntry extends Model
{
    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return BelongsTo<TaxDeclaration, $this>
     */
    public function declaration(): BelongsTo
    {
        return $this->belongsTo(TaxDeclaration::class, 'tax_declaration_id');
    }
}
