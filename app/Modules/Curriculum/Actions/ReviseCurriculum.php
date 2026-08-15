<?php

declare(strict_types=1);

namespace App\Modules\Curriculum\Actions;

use App\Modules\Curriculum\Domain\CurriculumPermission;
use App\Modules\Curriculum\Domain\CurriculumStatus;
use App\Modules\Curriculum\Models\Competency;
use App\Modules\Curriculum\Models\Curriculum;
use App\Modules\Curriculum\Models\CurriculumTopic;
use App\Modules\Curriculum\Models\CurriculumUnit;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The versioning half of the gap-#2 contract: a published curriculum is
 * never edited in place - a change is a NEW version. This clones the
 * published version (units, topics, competencies AND the topic-competency
 * links) as version max + 1 in draft state, ready for editing.
 *
 * Refused when the identity already carries a draft: two open drafts of
 * one programme is two contradictory sources of truth.
 */
final class ReviseCurriculum
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    public function handle(int $curriculumId, Actor $actor): Curriculum
    {
        Gate::authorize(CurriculumPermission::MANAGE);

        return DB::transaction(function () use ($curriculumId, $actor): Curriculum {
            /** @var Curriculum $source */
            $source = Curriculum::query()->lockForUpdate()->findOrFail($curriculumId);

            if (! $source->isPublished()) {
                throw new DomainException(
                    'Only a published curriculum can be revised; a draft is editable directly.'
                );
            }

            $identity = Curriculum::query()
                ->where('subject_id', $source->subject_id)
                ->where('class_level_id', $source->class_level_id)
                ->where('academic_year_id', $source->academic_year_id)
                ->where('sub_system', $source->sub_system->value)
                ->lockForUpdate();

            if ((clone $identity)->where('status', CurriculumStatus::Draft->value)->exists()) {
                throw new DomainException(
                    'A draft revision of this curriculum already exists; edit and '
                    .'publish it rather than opening a second one.'
                );
            }

            $nextVersion = 1 + (int) $identity->max('version');

            $draft = Curriculum::query()->create([
                'subject_id' => $source->subject_id,
                'class_level_id' => $source->class_level_id,
                'academic_year_id' => $source->academic_year_id,
                'sub_system' => $source->sub_system->value,
                'title' => $source->title,
                'description' => $source->description,
                'version' => $nextVersion,
                'status' => CurriculumStatus::Draft->value,
            ]);

            // Clone competencies first so topic links can be remapped.
            /** @var array<int, int> $competencyMap old id => new id */
            $competencyMap = [];

            foreach ($source->competencies()->orderBy('id')->get() as $competency) {
                $clone = Competency::query()->create([
                    'curriculum_id' => (int) $draft->getKey(),
                    'code' => $competency->code,
                    'descriptor' => $competency->descriptor,
                ]);
                $competencyMap[(int) $competency->getKey()] = (int) $clone->getKey();
            }

            foreach ($source->units()->orderBy('sequence')->get() as $unit) {
                $unitClone = CurriculumUnit::query()->create([
                    'curriculum_id' => (int) $draft->getKey(),
                    'title' => $unit->title,
                    'description' => $unit->description,
                    'sequence' => $unit->sequence,
                ]);

                foreach ($unit->topics()->orderBy('sequence')->get() as $topic) {
                    $topicClone = CurriculumTopic::query()->create([
                        'curriculum_unit_id' => (int) $unitClone->getKey(),
                        'title' => $topic->title,
                        'learning_outcome' => $topic->learning_outcome,
                        'sequence' => $topic->sequence,
                    ]);

                    // Remap the topic's competency links onto the clones.
                    foreach ($topic->competencies()->pluck('competencies.id') as $oldCompetencyId) {
                        $newCompetencyId = $competencyMap[(int) $oldCompetencyId] ?? null;

                        if ($newCompetencyId !== null) {
                            $topicClone->competencies()->attach($newCompetencyId);
                        }
                    }
                }
            }

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Curriculum',
                auditableType: Curriculum::class,
                auditableId: (int) $draft->getKey(),
                after: [
                    'revised_from_id' => (int) $source->getKey(),
                    'revised_from_version' => $source->version,
                    'version' => $nextVersion,
                    'status' => CurriculumStatus::Draft->value,
                ],
                actor: $actor,
            );

            return $draft->refresh();
        });
    }
}
