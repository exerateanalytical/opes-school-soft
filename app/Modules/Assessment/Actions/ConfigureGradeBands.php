<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Actions;

use App\Modules\Assessment\Models\AssessmentFramework;
use App\Modules\Assessment\Models\GradeBand;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Support\Audit\Actor;
use App\Support\Score\Score;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Saves a COMPLETE grade ladder for one
 * (framework, purpose, scale_basis, class_level) tuple, or saves nothing
 * (docs/specs/01-assessment.md 3.3, test obligation T12).
 *
 * Bands are replaced as a set, never edited one at a time. That is the whole
 * design: a per-row save can only ever be validated against its neighbours as
 * they happen to stand mid-edit, so every intermediate state of a legitimate
 * rearrangement looks like a gap or an overlap - and a validator that has to
 * allow those cannot reject them at all. Taking the set means the invariant is
 * checked against the ladder the operator actually intends.
 *
 * The four clauses, all blocking:
 *
 *   1. the ladder starts at 0;
 *   2. every band's max equals the next band's min - no gaps, no overlaps;
 *   3. the last band's max equals the scale ceiling;
 *   4. the top band is closed, so a perfect score bands.
 *
 * Clause 4 needs no separate check: closure is a property of the top band's
 * upper bound, which clause 3 pins to the ceiling. An "open top band" is a
 * ladder that stops short of the ceiling, and that is what is rejected - a
 * ladder ending at 18 on a /20 framework leaves 18..20 unbandable, so a
 * student who scores 19 prints a blank mention. That is v1's blank-grade bug
 * arriving by a different door.
 *
 * Comparison is in integer thousandths via Score. Comparing DECIMAL(6,3)
 * columns as floats would make 12.000 and 11.999999 indistinguishable and let
 * a hairline gap through (00-core 7.1).
 */
final class ConfigureGradeBands
{
    /** See CreateFramework::PERMISSION. */
    public const PERMISSION = CreateFramework::PERMISSION;

    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    /**
     * @param  list<array<string, mixed>>  $bands  each: min_score, max_score, label,
     *                                             label_fr, and optionally mention,
     *                                             grade_point, is_pass, colour
     * @return list<GradeBand> the saved ladder, ordered by min_score
     */
    public function handle(
        int $frameworkId,
        array $bands,
        Actor $actor,
        string $purpose = GradeBand::PURPOSE_INTERNAL,
        string $scaleBasis = GradeBand::BASIS_OUT_OF_MAX,
        ?int $classLevelId = null,
    ): array {
        Gate::authorize(self::PERMISSION);

        if (! in_array($purpose, GradeBand::PURPOSES, true)) {
            throw new DomainException('purpose must be internal|exam_o_level|exam_a_level (01-assessment 3.3).');
        }

        if (! in_array($scaleBasis, GradeBand::BASES, true)) {
            throw new DomainException('scale_basis must be out_of_max|percentage (01-assessment 3.3).');
        }

        return DB::transaction(function () use ($frameworkId, $bands, $actor, $purpose, $scaleBasis, $classLevelId): array {
            /** @var AssessmentFramework $framework */
            $framework = AssessmentFramework::query()->lockForUpdate()->findOrFail($frameworkId);

            $ceiling = Score::of($framework->scaleCeiling($scaleBasis));
            $ordered = self::validateCoverage($bands, $ceiling);

            // Replace the tuple wholesale. Scoped to the tuple and not to the
            // framework: an internal /20 ladder and an O-Level percentage
            // ladder coexist on one framework and must not delete each other.
            GradeBand::query()
                ->where('framework_id', $framework->id)
                ->where('purpose', $purpose)
                ->where('scale_basis', $scaleBasis)
                ->where('class_level_id_key', $classLevelId ?? 0)
                ->delete();

            $saved = [];

            foreach ($ordered as $index => $band) {
                $saved[] = GradeBand::query()->create([
                    'framework_id' => $framework->id,
                    'purpose' => $purpose,
                    'scale_basis' => $scaleBasis,
                    'class_level_id' => $classLevelId,
                    'min_score' => $band['min']->toString(),
                    'max_score' => $band['max']->toString(),
                    'label' => $band['label'],
                    'label_fr' => $band['label_fr'],
                    'mention' => $band['mention'],
                    'grade_point' => $band['grade_point'],
                    'is_pass' => $band['is_pass'],
                    'colour' => $band['colour'],
                    'order_index' => $index + 1,
                ]);
            }

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Assessment',
                auditableType: AssessmentFramework::class,
                auditableId: (int) $framework->getKey(),
                after: [
                    'grade_bands' => sprintf('%s/%s/%s', $purpose, $scaleBasis, $classLevelId ?? 'all levels'),
                    'ceiling' => $ceiling->toString(),
                    'intervals' => array_map(
                        static fn (array $b): string => $b['min']->toString().'..'.$b['max']->toString(),
                        $ordered,
                    ),
                ],
                actor: $actor,
            );

            return $saved;
        });
    }

    /**
     * The validator. Pure, static and separately callable so a configuration
     * screen can show the operator the failure before it tries to save.
     *
     * @param  list<array<string, mixed>>  $bands
     * @return list<array{min: Score, max: Score, label: string, label_fr: string, mention: string|null, grade_point: string|null, is_pass: bool, colour: string|null}>
     *                                                                                                                                                                 ordered by min_score
     */
    public static function validateCoverage(array $bands, Score $ceiling): array
    {
        if ($bands === []) {
            throw new DomainException(
                'A grade ladder needs at least one band; a framework with no bands cannot be published against '
                .'(01-assessment 3.3).'
            );
        }

        $parsed = [];

        foreach ($bands as $i => $band) {
            $min = self::scoreAt($band, 'min_score', $i);
            $max = self::scoreAt($band, 'max_score', $i);

            if ($min->thousandths() >= $max->thousandths()) {
                throw new DomainException(sprintf(
                    'Band [%s, %s) is empty or inverted: min_score must be strictly less than max_score.',
                    $min->toString(),
                    $max->toString(),
                ));
            }

            $parsed[] = [
                'min' => $min,
                'max' => $max,
                'label' => self::stringAt($band, 'label', $i),
                'label_fr' => self::stringAt($band, 'label_fr', $i),
                'mention' => self::optionalStringAt($band, 'mention'),
                'grade_point' => self::optionalStringAt($band, 'grade_point'),
                'is_pass' => (bool) ($band['is_pass'] ?? false),
                'colour' => self::optionalStringAt($band, 'colour'),
            ];
        }

        usort(
            $parsed,
            static fn (array $a, array $b): int => $a['min']->thousandths() <=> $b['min']->thousandths(),
        );

        // Clause 1.
        if ($parsed[0]['min']->thousandths() !== 0) {
            throw new DomainException(sprintf(
                'The grade ladder must start at 0: the lowest band starts at %s, so any score below it bands to nothing.',
                $parsed[0]['min']->toString(),
            ));
        }

        // Clause 2 - contiguity. A gap and an overlap are the same comparison
        // with opposite signs, and both are named explicitly: telling an
        // operator only that "the bands are wrong" leaves them to find the
        // offending pair by eye.
        $count = count($parsed);

        for ($i = 0; $i < $count - 1; $i++) {
            $thisMax = $parsed[$i]['max'];
            $nextMin = $parsed[$i + 1]['min'];

            if ($thisMax->thousandths() < $nextMin->thousandths()) {
                throw new DomainException(sprintf(
                    'Gap in the grade ladder: band [%s, %s) ends where band [%s, %s) has not yet begun, '
                    .'so a score in [%s, %s) bands to nothing.',
                    $parsed[$i]['min']->toString(),
                    $thisMax->toString(),
                    $nextMin->toString(),
                    $parsed[$i + 1]['max']->toString(),
                    $thisMax->toString(),
                    $nextMin->toString(),
                ));
            }

            if ($thisMax->thousandths() > $nextMin->thousandths()) {
                throw new DomainException(sprintf(
                    'Overlap in the grade ladder: band [%s, %s) and band [%s, %s) both claim [%s, %s), '
                    .'so the same score bands two ways.',
                    $parsed[$i]['min']->toString(),
                    $thisMax->toString(),
                    $nextMin->toString(),
                    $parsed[$i + 1]['max']->toString(),
                    $nextMin->toString(),
                    $thisMax->toString(),
                ));
            }
        }

        // Clauses 3 and 4. The top band is closed by construction once its
        // upper bound is the ceiling; a ladder stopping short leaves the top
        // of the scale unbandable, which is what "open top band" means here.
        $top = $parsed[$count - 1]['max'];

        if ($top->thousandths() < $ceiling->thousandths()) {
            throw new DomainException(sprintf(
                'The top band is open: the ladder ends at %s but the scale runs to %s, '
                .'so a score in [%s, %s] bands to nothing and the bulletin prints a blank mention.',
                $top->toString(),
                $ceiling->toString(),
                $top->toString(),
                $ceiling->toString(),
            ));
        }

        if ($top->thousandths() > $ceiling->thousandths()) {
            throw new DomainException(sprintf(
                'The top band runs to %s, past the scale ceiling of %s: no score can reach it.',
                $top->toString(),
                $ceiling->toString(),
            ));
        }

        return $parsed;
    }

    /**
     * @param  array<string, mixed>  $band
     */
    private static function scoreAt(array $band, string $key, int $index): Score
    {
        $value = $band[$key] ?? null;

        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value) || $value === '') {
            throw new DomainException(sprintf('Band %d is missing a decimal-string %s.', $index + 1, $key));
        }

        return Score::of($value);
    }

    /**
     * @param  array<string, mixed>  $band
     */
    private static function stringAt(array $band, string $key, int $index): string
    {
        $value = $band[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new DomainException(sprintf('Band %d is missing %s.', $index + 1, $key));
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $band
     */
    private static function optionalStringAt(array $band, string $key): ?string
    {
        $value = $band[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
