<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Models;

use Database\Factories\ReportCardConfigFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * docs/specs/01-assessment.md 13.1 - the configurator head.
 *
 * The VERSIONS deliberately have no Eloquent model. They are reached through
 * the query builder from here and from the Actions, for two reasons that both
 * matter:
 *
 *  1. A frozen version is immutable and the database enforces that with a
 *     BEFORE UPDATE trigger. An Eloquent model invites `->update()`, `->save()`
 *     and mass assignment on rows whose whole contract is that they cannot be
 *     written - the model would exist only to be defended against.
 *  2. Nothing in the product ever loads a version as a rich object. Publication
 *     pins its id; render decodes its `payload`. Two columns.
 *
 * @property int $id
 * @property int|null $framework_id
 * @property string $code
 * @property string $name
 * @property string $name_fr
 * @property bool $is_active
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class ReportCardConfig extends Model
{
    /** @use HasFactory<ReportCardConfigFactory> */
    use HasFactory;

    /**
     * 13.5's complete column-key set, verbatim. A configurator that accepts a
     * key not on this list produces a card with a silently blank column, which
     * is the failure mode 13.5 exists to end.
     *
     * @var list<string>
     */
    public const COLUMN_KEYS = [
        'subject_name',
        'subject_group',
        'period_score',
        'subject_score',
        'coefficient',
        'score_times_coef',
        'subject_rank',
        'cote_min_max',
        'class_average_subject',
        'appreciation',
        'teacher_name',
        'teacher_visa',
        'competencies_assessed',
        'annual_average',
        'previous_term_average',
        'component_score',
        'grade_letter',
        'grade_point',
    ];

    /**
     * 13.7's toggleable blocks. `absence_hours` is present and is expected to
     * render a documented hole while per-lesson attendance is unbuilt - see 14
     * and RenderReportCard.
     *
     * @var list<string>
     */
    public const BLOCK_KEYS = [
        'state_header',
        'student_identity',
        'subject_table',
        'totals_row',
        'general_average_and_rank',
        'mention',
        'gpa',
        'conduct',
        'absence_hours',
        'conseil_award',
        'remarks',
        'class_statistics',
        'previous_period_average',
        'annual_average',
        'fee_balance',
        'signatures',
        'qr_verification',
        'version_and_issue_date',
    ];

    /** @var list<string> */
    protected $fillable = [
        'framework_id',
        'code',
        'name',
        'name_fr',
        'is_active',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'framework_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The version a publication would pin today: the highest `version_no`.
     *
     * Returns the id only. Callers that need the payload decode it themselves
     * from the row, and RENDER never calls this at all - it reads the version
     * the snapshot pinned, which is the entire point of 13.1.
     */
    public function currentVersionId(): ?int
    {
        $id = DB::table('report_card_config_versions')
            ->where('config_id', '=', $this->getKey())
            ->orderByDesc('version_no')
            ->value('id');

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function versionPayload(int $versionId): array
    {
        $raw = DB::table('report_card_config_versions')
            ->where('id', '=', $versionId)
            ->value('payload');

        if (! is_string($raw)) {
            throw new RuntimeException(
                "Report card config version {$versionId} has no payload; a snapshot cannot be rendered "
                .'without the layout it was issued under (01-assessment 13.1).'
            );
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    protected static function newFactory(): ReportCardConfigFactory
    {
        return ReportCardConfigFactory::new();
    }
}
