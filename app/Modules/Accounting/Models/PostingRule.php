<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Modules\Accounting\Domain\PostingEvent;
use Database\Factories\PostingRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use App\Support\Retention\Immutable10Year;

/**
 * docs/specs/02-accounting.md §11.1. Persistence only - versioning,
 * ambiguity checks and locking are enforced by SavePostingRule /
 * ValidatePostingRules / PostFromEvent, never by model events.
 *
 * @property int $id
 * @property string $code
 * @property int $version
 * @property string $event
 * @property int $journal_id
 * @property string $label_expression
 * @property string|null $condition_expression
 * @property int $priority
 * @property bool $is_active
 * @property bool $is_locked
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to exclusive
 * @property int|null $created_by
 * @property int|null $approved_by
 */
final class PostingRule extends Model
{
    use Immutable10Year;
    /** @use HasFactory<PostingRuleFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'code', 'version', 'event', 'journal_id', 'label_expression',
        'condition_expression', 'priority', 'is_active', 'is_locked',
        'effective_from', 'effective_to', 'created_by', 'approved_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'priority' => 'integer',
            'is_active' => 'boolean',
            'is_locked' => 'boolean',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    protected static function newFactory(): PostingRuleFactory
    {
        return PostingRuleFactory::new();
    }

    /**
     * @return HasMany<PostingRuleLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PostingRuleLine::class)->orderBy('sequence');
    }

    /**
     * @return BelongsTo<Journal, $this>
     */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function postingEvent(): PostingEvent
    {
        return PostingEvent::from($this->event);
    }

    /**
     * `[effective_from, effective_to)` - effective_to exclusive.
     */
    public function coversDate(Carbon $date): bool
    {
        if ($date->lt($this->effective_from)) {
            return false;
        }

        return $this->effective_to === null || $date->lt($this->effective_to);
    }
}
