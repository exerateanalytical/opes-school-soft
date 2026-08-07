<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Actions;

use App\Modules\Assessment\Models\AssessmentComponent;
use App\Modules\Assessment\Models\AssessmentFramework;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Support\Audit\Actor;
use App\Support\Score\Score;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Declares a framework's assessment components - the columns of the entry grid
 * and of the printed card (docs/specs/01-assessment.md 5.3).
 *
 * Components are UPSERTED by code, never replaced wholesale as grade bands
 * are, and the difference matters: a `Mark` row points at a component id, so
 * deleting and recreating a component would orphan every mark ever entered
 * against it. A component that leaves the grid is deactivated
 * (`is_active = 0`), which is also 00-core 10.5's rule for anything a Mark can
 * reference.
 *
 * `max_score` is the component's OWN maximum. Stage 2 divides by it, so a CA
 * out of 30 and an exam out of 100 normalise correctly instead of both being
 * measured against the framework's 20 - which is the entire 2.1 correction.
 */
final class DefineComponents
{
    /** See CreateFramework::PERMISSION. */
    public const PERMISSION = CreateFramework::PERMISSION;

    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    /**
     * @param  list<array<string, mixed>>  $components  each: code, name, name_fr,
     *                                                  max_score, and optionally
     *                                                  order_index, is_active
     * @return list<AssessmentComponent> ordered as declared
     */
    public function handle(int $frameworkId, array $components, Actor $actor): array
    {
        Gate::authorize(self::PERMISSION);

        if ($components === []) {
            throw new DomainException(
                'A framework needs at least one component; with none, every subject has no column to be marked in '
                .'(01-assessment 5.3).'
            );
        }

        return DB::transaction(function () use ($frameworkId, $components, $actor): array {
            /** @var AssessmentFramework $framework */
            $framework = AssessmentFramework::query()->lockForUpdate()->findOrFail($frameworkId);

            $parsed = $this->validated($components);
            $saved = [];

            foreach ($parsed as $index => $component) {
                /** @var AssessmentComponent $model */
                $model = AssessmentComponent::query()->updateOrCreate(
                    ['framework_id' => $framework->id, 'code' => $component['code']],
                    [
                        'name' => $component['name'],
                        'name_fr' => $component['name_fr'],
                        'max_score' => $component['max_score'],
                        'order_index' => $component['order_index'] ?? $index + 1,
                        'is_active' => $component['is_active'],
                    ],
                );

                $saved[] = $model;
            }

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Assessment',
                auditableType: AssessmentFramework::class,
                auditableId: (int) $framework->getKey(),
                after: [
                    'components' => array_map(
                        static fn (AssessmentComponent $c): string => $c->code.' /'.$c->max_score,
                        $saved,
                    ),
                ],
                actor: $actor,
            );

            return $saved;
        });
    }

    /**
     * @param  list<array<string, mixed>>  $components
     * @return list<array{code: string, name: string, name_fr: string, max_score: string, order_index: int|null, is_active: bool}>
     */
    private function validated(array $components): array
    {
        $parsed = [];
        $seen = [];

        foreach ($components as $i => $component) {
            $code = $component['code'] ?? null;

            if (! is_string($code) || trim($code) === '') {
                throw new DomainException(sprintf('Component %d is missing a code.', $i + 1));
            }

            $code = trim($code);

            // The UNIQUE index would catch this too, but only after the first
            // few rows had already been written and then rolled back; naming
            // the duplicate is more useful than an SQLSTATE.
            if (isset($seen[$code])) {
                throw new DomainException(sprintf(
                    'Component code `%s` is declared twice; codes are unique per framework (01-assessment 5.3).',
                    $code,
                ));
            }

            $seen[$code] = true;

            $maxScore = $component['max_score'] ?? null;

            if (is_int($maxScore)) {
                $maxScore = (string) $maxScore;
            }

            if (! is_string($maxScore) || $maxScore === '') {
                throw new DomainException(sprintf('Component `%s` needs a decimal-string max_score.', $code));
            }

            $max = Score::of($maxScore);

            // A component with a zero maximum has no unit ratio: stage 2 would
            // divide by it. Refused here rather than allowed to become a
            // division-by-zero at composition time.
            if ($max->thousandths() === 0) {
                throw new DomainException(sprintf(
                    'Component `%s` has a maximum of zero, so no mark entered against it can be normalised '
                    .'(01-assessment 6.3).',
                    $code,
                ));
            }

            $orderIndex = $component['order_index'] ?? null;

            $parsed[] = [
                'code' => $code,
                'name' => $this->stringAt($component, 'name', $code),
                'name_fr' => $this->stringAt($component, 'name_fr', $code),
                'max_score' => $max->toString(),
                'order_index' => is_int($orderIndex) ? $orderIndex : null,
                'is_active' => (bool) ($component['is_active'] ?? true),
            ];
        }

        return $parsed;
    }

    /**
     * @param  array<string, mixed>  $component
     */
    private function stringAt(array $component, string $key, string $code): string
    {
        $value = $component[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new DomainException(sprintf('Component `%s` is missing %s.', $code, $key));
        }

        return $value;
    }
}
