<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use Database\Factories\AnalyticAxisFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * docs/specs/02-accounting.md §12.2 - the analytic DIMENSION (SECTION,
 * ACTIVITY, SITE, PROJECT). Persistence only: AN-1..AN-4 live in the
 * Actions (AllocateLineAnalytics, ConfigureAnalyticValue,
 * VerifyAnalyticAllocations), not in model events, per this module's
 * convention.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $name_fr
 * @property bool $is_mandatory
 * @property list<int>|null $applies_to_classes
 * @property bool $requires_full_allocation
 * @property int $display_order
 * @property bool $is_active
 * @property bool $is_archived
 */
final class AnalyticAxis extends Model
{
    /** @use HasFactory<AnalyticAxisFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'name_fr',
        'is_mandatory',
        'applies_to_classes',
        'requires_full_allocation',
        'display_order',
        'is_active',
        'is_archived',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_mandatory' => 'boolean',
            'applies_to_classes' => 'array',
            'requires_full_allocation' => 'boolean',
            'display_order' => 'integer',
            'is_active' => 'boolean',
            'is_archived' => 'boolean',
        ];
    }

    protected static function newFactory(): AnalyticAxisFactory
    {
        return AnalyticAxisFactory::new();
    }

    /**
     * @return HasMany<AnalyticValue, $this>
     */
    public function values(): HasMany
    {
        return $this->hasMany(AnalyticValue::class);
    }

    /** AN-3: does this axis bind lines on an account of the given class? */
    public function appliesToClass(int $accountClass): bool
    {
        $classes = $this->applies_to_classes;

        return $classes === null || in_array($accountClass, $classes, true);
    }
}
