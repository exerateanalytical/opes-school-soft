<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The byte-level DIPE export definition (docs/specs/05-hr-payroll.md 11.4).
 * Ships as ONE UNPOPULATED row: `fields` NULL, `is_active` false. ExportDipe
 * refuses (DipeLayoutUnconfigured) until an operator populates it from the
 * verified CNPS layout - the CHECK constraint forbids activating an empty
 * definition.
 *
 * Each populated field is {name, offset, length, alignment, padding, source,
 * format?}: offset 1-based, alignment left|right, `source` a dot-path into
 * the PayrollItemSnapshot payload.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property int|null $record_length
 * @property list<array<string, mixed>>|null $fields
 * @property bool $is_active
 * @property string $source_citation
 * @property Carbon|null $verified_on
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class DipeLayout extends Model
{
    /** The seeded magnetic-export layout code. */
    public const MAGNETIC_CODE = 'edipe_magnetic';

    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'record_length',
        'fields',
        'is_active',
        'source_citation',
        'verified_on',
    ];

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'record_length' => 'integer',
            'fields' => 'array',
            'is_active' => 'boolean',
            'verified_on' => 'date',
        ];
    }
}
