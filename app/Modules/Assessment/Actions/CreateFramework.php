<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Actions;

use App\Modules\Assessment\Domain\FrameworkFamily;
use App\Modules\Assessment\Domain\MissingComponentPolicy;
use App\Modules\Assessment\Models\AssessmentFramework;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Support\Audit\Actor;
use App\Support\Score\Score;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Creates the per-section, per-year assessment framework of
 * docs/specs/01-assessment.md 3.1.
 *
 * Nothing here is defaulted into existence: a framework declares the scale, the
 * pass threshold and the composition rules that every later stage reads, so a
 * wrong value is not a wrong screen, it is a wrong bulletin. The combinations
 * the spec forbids are refused at save rather than tolerated and worked around
 * downstream.
 */
final class CreateFramework
{
    /**
     * Kept as a raw string, matching the Academics Actions: Gate::authorize
     * takes an ability name and the enum case would have to be unwrapped at
     * every call site anyway. Permission::AssessmentConfigure holds the same
     * value and LocalisationTest keeps the two honest.
     */
    public const PERMISSION = 'assessment.configure';

    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(array $attributes, Actor $actor): AssessmentFramework
    {
        Gate::authorize(self::PERMISSION);

        return DB::transaction(function () use ($attributes, $actor): AssessmentFramework {
            $payload = $this->validated($attributes);

            $framework = AssessmentFramework::query()->create($payload);

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Assessment',
                auditableType: AssessmentFramework::class,
                auditableId: (int) $framework->getKey(),
                after: [
                    'code' => $framework->code,
                    'family' => $framework->family->value,
                    'assessment_mode' => $framework->assessment_mode,
                    'max_score' => $framework->max_score,
                    'pass_score' => $framework->pass_score,
                    'missing_component_policy' => $framework->missing_component_policy->value,
                ],
                actor: $actor,
            );

            return $framework;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function validated(array $attributes): array
    {
        $family = $this->family($attributes['family'] ?? null);
        $mode = $this->oneOf($attributes['assessment_mode'] ?? AssessmentFramework::MODE_NUMERIC, AssessmentFramework::MODES, 'assessment_mode');

        // 01-assessment 3.2 and 8: Family F produces observations. A nursery
        // framework carrying coefficients or a rank would let a maternelle
        // bulletin print a class position, which T19 forbids outright.
        if ($family->isCompetencyOnly() && $mode !== AssessmentFramework::MODE_COMPETENCY) {
            throw new DomainException(sprintf(
                'Family F is competency-only (01-assessment 3.2); assessment_mode must be `competency`, got `%s`.',
                $mode,
            ));
        }

        $usesCoefficients = (bool) ($attributes['uses_coefficients'] ?? ! $family->isCompetencyOnly());
        $usesRank = (bool) ($attributes['uses_rank'] ?? ! $family->isCompetencyOnly());

        if ($family->isCompetencyOnly() && ($usesCoefficients || $usesRank)) {
            throw new DomainException(
                'Family F carries no coefficients and no rank (01-assessment 3.2, 8.4).'
            );
        }

        $perLessonAttendance = (bool) ($attributes['requires_per_lesson_attendance'] ?? $family->isMinesecSecondary());

        // 01-assessment 14. The MINESEC bulletin has an attendance block that
        // can only be filled from per-lesson registers; a framework that does
        // not require them cannot produce a complete card, and discovering
        // that in June is too late.
        if ($family->isMinesecSecondary() && ! $perLessonAttendance) {
            throw new DomainException(sprintf(
                'Family %s is a MINESEC secondary family and requires per-lesson attendance (01-assessment 14).',
                $family->value,
            ));
        }

        $maxScore = $this->score($attributes['max_score'] ?? null, 'max_score');
        $passScore = $this->score($attributes['pass_score'] ?? null, 'pass_score');

        // The database CHECK says the same thing. It is repeated here so the
        // operator gets a sentence rather than an SQLSTATE.
        if ($passScore->thousandths() === 0 || $passScore->thousandths() > $maxScore->thousandths()) {
            throw new DomainException(sprintf(
                'pass_score must satisfy 0 < pass_score <= max_score; got %s against a maximum of %s.',
                $passScore->toString(),
                $maxScore->toString(),
            ));
        }

        $precision = (int) ($attributes['score_precision'] ?? 2);

        // Score holds thousandths, so nothing finer than 3 dp can be
        // represented and pretending otherwise would round silently.
        if ($precision < 0 || $precision > 3) {
            throw new DomainException(sprintf('score_precision must be 0..3, got %d.', $precision));
        }

        return [
            'school_section_id' => (int) ($attributes['school_section_id'] ?? 0),
            'academic_year_id' => (int) ($attributes['academic_year_id'] ?? 0),
            'code' => (string) ($attributes['code'] ?? ''),
            'name' => (string) ($attributes['name'] ?? ''),
            'name_fr' => (string) ($attributes['name_fr'] ?? ''),
            'family' => $family,
            'assessment_mode' => $mode,
            'max_score' => $maxScore->toString(),
            'pass_score' => $passScore->toString(),
            'score_precision' => $precision,
            'uses_coefficients' => $usesCoefficients,
            'uses_rank' => $usesRank,
            'rank_scope' => $this->oneOf($attributes['rank_scope'] ?? 'class_group', AssessmentFramework::RANK_SCOPES, 'rank_scope'),
            'rank_cohort_rule' => $this->oneOf($attributes['rank_cohort_rule'] ?? 'same_stream', AssessmentFramework::RANK_COHORT_RULES, 'rank_cohort_rule'),
            'annual_composition' => $this->oneOf($attributes['annual_composition'] ?? 'mean_of_leaf_periods', AssessmentFramework::ANNUAL_COMPOSITIONS, 'annual_composition'),
            'requires_conseil' => (bool) ($attributes['requires_conseil'] ?? false),
            'requires_hod_validation' => (bool) ($attributes['requires_hod_validation'] ?? true),
            'requires_per_lesson_attendance' => $perLessonAttendance,
            'missing_component_policy' => $this->policy($attributes['missing_component_policy'] ?? null),
            'min_periods_assessed' => (int) ($attributes['min_periods_assessed'] ?? 1),
            'gpa_scale_id' => isset($attributes['gpa_scale_id']) ? (int) $attributes['gpa_scale_id'] : null,
            'is_default' => (bool) ($attributes['is_default'] ?? false),
            'is_active' => (bool) ($attributes['is_active'] ?? true),
        ];
    }

    private function family(mixed $value): FrameworkFamily
    {
        if ($value instanceof FrameworkFamily) {
            return $value;
        }

        $family = is_string($value) ? FrameworkFamily::tryFrom($value) : null;

        if ($family === null) {
            throw new DomainException('family must be one of A..F (01-assessment 3.2).');
        }

        return $family;
    }

    private function policy(mixed $value): MissingComponentPolicy
    {
        if ($value instanceof MissingComponentPolicy) {
            return $value;
        }

        if ($value === null) {
            return MissingComponentPolicy::Redistribute;
        }

        $policy = is_string($value) ? MissingComponentPolicy::tryFrom($value) : null;

        if ($policy === null) {
            throw new DomainException(
                'missing_component_policy must be redistribute|zero|block_publication (01-assessment 6.4).'
            );
        }

        return $policy;
    }

    /**
     * @param  list<string>  $allowed
     */
    private function oneOf(mixed $value, array $allowed, string $field): string
    {
        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            throw new DomainException(sprintf(
                '%s must be one of %s.',
                $field,
                implode('|', $allowed),
            ));
        }

        return $value;
    }

    /**
     * Scores cross this boundary as strings and are parsed by Score, never by
     * (float) - 00-core 7.1. A malformed value is refused here rather than
     * silently becoming 0.000 in a DECIMAL column.
     */
    private function score(mixed $value, string $field): Score
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value) || $value === '') {
            throw new DomainException(sprintf('%s is required and must be a decimal string.', $field));
        }

        return Score::of($value);
    }
}
