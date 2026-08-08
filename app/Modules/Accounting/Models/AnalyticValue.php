<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use Database\Factories\AnalyticValueFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * docs/specs/02-accounting.md §12.2 - the analytic MEMBER (a section, an
 * activity, a campus). `linked_type`/`linked_id` are plain columns pointing
 * at another module's row (SchoolSection, Route, Hostel) - never a
 * polymorphic Eloquent relation, because 00-core §6.2 forbids importing
 * another module's Models.
 *
 * AN-4 (no archiving while referenced by an unclosed fiscal year) is
 * enforced by ConfigureAnalyticValue, not here.
 *
 * @property int $id
 * @property int $analytic_axis_id
 * @property string $code
 * @property string $name
 * @property string $name_fr
 * @property int|null $parent_id
 * @property string|null $linked_type
 * @property int|null $linked_id
 * @property bool $is_active
 * @property bool $is_archived
 */
final class AnalyticValue extends Model
{
    /** @use HasFactory<AnalyticValueFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'analytic_axis_id',
        'code',
        'name',
        'name_fr',
        'parent_id',
        'linked_type',
        'linked_id',
        'is_active',
        'is_archived',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_archived' => 'boolean',
        ];
    }

    protected static function newFactory(): AnalyticValueFactory
    {
        return AnalyticValueFactory::new();
    }

    /**
     * @return BelongsTo<AnalyticAxis, $this>
     */
    public function axis(): BelongsTo
    {
        return $this->belongsTo(AnalyticAxis::class, 'analytic_axis_id');
    }

    /**
     * @return BelongsTo<AnalyticValue, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<AnalyticValue, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return HasMany<JournalEntryLineAnalytic, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(JournalEntryLineAnalytic::class, 'analytic_value_id');
    }
}
