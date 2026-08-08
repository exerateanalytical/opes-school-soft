<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * docs/specs/01-assessment.md 13.3 - the issued report card.
 *
 * "The snapshot is authoritative. Re-render, portal display, transcript
 * assembly and the Statement of Results read the snapshot and never
 * recompute."
 *
 * This model is therefore deliberately inert: it has no method that reaches
 * back to `marks`, `subject_allocations`, `grade_bands` or the config head, and
 * `RenderReportCard` is built so that it cannot. The database enforces the
 * same thing from below (trg_report_card_snapshots_immutable).
 *
 * @property int $id
 * @property int $enrollment_id
 * @property int $assessment_period_id
 * @property int $class_group_id
 * @property int $period_publication_id
 * @property int $generation
 * @property string $snapshot_batch_id
 * @property int $report_card_config_version_id
 * @property array<string, mixed> $payload
 * @property string $payload_hash
 * @property Carbon $issued_at
 * @property string|null $pdf_hash
 * @property array<string, mixed>|null $applied_policy_notes
 * @property int|null $superseded_by_snapshot_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class ReportCardSnapshot extends Model
{
    // There is deliberately NO factory. A snapshot is issued by PublishPeriod
    // or AmendMarks and by nothing else; a factory would let a test - and then
    // a seeder, and then a support script - mint an issued report card whose
    // numbers were never produced by the pipeline. Every snapshot in every test
    // in this module comes out of a real publication.

    /** @var list<string> */
    protected $fillable = [
        'enrollment_id',
        'assessment_period_id',
        'class_group_id',
        'period_publication_id',
        'generation',
        'snapshot_batch_id',
        'report_card_config_version_id',
        'payload',
        'payload_hash',
        'issued_at',
        'pdf_hash',
        'applied_policy_notes',
        'superseded_by_snapshot_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enrollment_id' => 'integer',
            'assessment_period_id' => 'integer',
            'class_group_id' => 'integer',
            'period_publication_id' => 'integer',
            'generation' => 'integer',
            'report_card_config_version_id' => 'integer',
            'payload' => 'array',
            'applied_policy_notes' => 'array',
            'issued_at' => 'datetime',
            'superseded_by_snapshot_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<PeriodPublication, $this>
     */
    public function publication(): BelongsTo
    {
        return $this->belongsTo(PeriodPublication::class, 'period_publication_id');
    }

    public function isSuperseded(): bool
    {
        return $this->superseded_by_snapshot_id !== null;
    }

    /**
     * Reduce a decoded JSON document to ONE deterministic byte string.
     *
     * 00-core 14 establishes the rule and the reason: MySQL renormalises `json`
     * columns on write - it reorders keys, respaces and unescapes - so the
     * bytes handed to the driver are not the bytes read back. A hash taken over
     * the stored string therefore fails the moment the row leaves memory, which
     * is exactly when T13 checks it. `AuditLog` solved this the same way; the
     * logic is restated here rather than imported because
     * tests/Architecture/ModuleBoundaryTest.php forbids Assessment from using
     * Identity's Models, without exception.
     *
     * `ksort` with SORT_STRING, not SORT_REGULAR: under SORT_REGULAR MySQL's
     * own numeric-looking keys would compare numerically on one platform and
     * lexically on another, and a hash that depends on the platform is not a
     * hash.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    public static function canonicalise(array $value): array
    {
        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::canonicalise($item);
            }
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    public static function canonicalJson(array $value): string
    {
        return json_encode(
            self::canonicalise($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    public static function hashOf(array $value): string
    {
        return hash('sha256', self::canonicalJson($value));
    }
}
