<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Models;

use Database\Factories\PeriodPublicationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * docs/specs/01-assessment.md 13.2 - C8, publication PER CLASS GROUP.
 *
 * The row is also the lock token for the whole module (00-core 11). Everything
 * that can contend - publishing, re-publishing, amending, entering marks -
 * contends on this one row, which is what lets T17 be a real statement rather
 * than a hope.
 *
 * The statuses are string constants rather than a backed enum because
 * App\Modules\Assessment\Domain is owned by other authors in this phase and a
 * duplicate enum there would be the one thing worse than constants here. They
 * mirror the migration's ENUM exactly; the database is the authority.
 *
 * @property int $id
 * @property int $assessment_period_id
 * @property int $class_group_id
 * @property string $status
 * @property string|null $snapshot_batch_id
 * @property int $generation
 * @property int|null $report_card_config_version_id
 * @property int|null $published_by
 * @property Carbon|null $published_at
 * @property int|null $unpublished_by
 * @property Carbon|null $unpublished_at
 * @property string|null $unpublish_reason
 * @property array<string, mixed>|null $blocking_report
 * @property int $version
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class PeriodPublication extends Model
{
    /** @use HasFactory<PeriodPublicationFactory> */
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_MARKS_OPEN = 'marks_open';

    public const STATUS_MARKS_CLOSED = 'marks_closed';

    /**
     * The claimed-but-not-finished state. Its existence is the difference
     * between a lock and a hope: the conditional UPDATE that sets it is what
     * a second publisher's own conditional UPDATE fails against.
     */
    public const STATUS_PUBLISHING = 'publishing';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_UNPUBLISHED = 'unpublished';

    /** @var list<string> */
    protected $fillable = [
        'assessment_period_id',
        'class_group_id',
        'status',
        'snapshot_batch_id',
        'generation',
        'report_card_config_version_id',
        'published_by',
        'published_at',
        'unpublished_by',
        'unpublished_at',
        'unpublish_reason',
        'blocking_report',
        'version',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'assessment_period_id' => 'integer',
            'class_group_id' => 'integer',
            'generation' => 'integer',
            'report_card_config_version_id' => 'integer',
            'blocking_report' => 'array',
            'published_at' => 'datetime',
            'unpublished_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    /**
     * @return HasMany<ReportCardSnapshot, $this>
     */
    public function snapshots(): HasMany
    {
        return $this->hasMany(ReportCardSnapshot::class, 'period_publication_id');
    }

    /**
     * 13.2: marks entry "is rejected unless the status is `marks_open`".
     */
    public function acceptsMarkEntry(): bool
    {
        return $this->status === self::STATUS_MARKS_OPEN;
    }

    /**
     * 13.2: un-publication "revokes portal visibility immediately". Snapshots
     * are retained; the card is simply no longer issuable.
     */
    public function isIssuable(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    protected static function newFactory(): PeriodPublicationFactory
    {
        return PeriodPublicationFactory::new();
    }
}
