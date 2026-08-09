<?php

declare(strict_types=1);

namespace App\Modules\Tax\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * docs/specs/03-tax-procurement.md §7.4 - a declarative compliance
 * obligation: what must be filed, how often, and a small `due_rule`
 * expression (data, not a hardcoded match) from which the calendar derives
 * upcoming due dates and T−15/7/1 alerts.
 *
 * Only the DSF row is seeded (§7.5, verified); everything else waits for
 * the accountant. Weekend/holiday roll-forward NEEDS VERIFICATION - the
 * statutory date is shown unadjusted with a note.
 *
 * @property int $id
 * @property int $tax_declaration_type_id
 * @property string $frequency
 * @property string $due_rule
 * @property array<string, mixed>|null $applies_when
 * @property string|null $penalty_note
 * @property string|null $legal_ref
 * @property bool $is_archived
 */
final class TaxObligation extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'tax_declaration_type_id',
        'frequency',
        'due_rule',
        'applies_when',
        'penalty_note',
        'legal_ref',
        'is_archived',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'applies_when' => 'array',
            'is_archived' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<TaxDeclarationType, $this>
     */
    public function declarationType(): BelongsTo
    {
        return $this->belongsTo(TaxDeclarationType::class, 'tax_declaration_type_id');
    }
}
