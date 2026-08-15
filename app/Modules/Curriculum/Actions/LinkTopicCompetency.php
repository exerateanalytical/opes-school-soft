<?php

declare(strict_types=1);

namespace App\Modules\Curriculum\Actions;

use App\Modules\Curriculum\Domain\CurriculumPermission;
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
 * Links a topic to a competency of the SAME curriculum version. A
 * cross-version (or cross-curriculum) link is refused: the pivot is
 * version content and must be cloneable with the version.
 */
final class LinkTopicCompetency
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    public function handle(int $topicId, int $competencyId, Actor $actor): void
    {
        Gate::authorize(CurriculumPermission::MANAGE);

        DB::transaction(function () use ($topicId, $competencyId, $actor): void {
            /** @var CurriculumTopic $topic */
            $topic = CurriculumTopic::query()->findOrFail($topicId);

            /** @var CurriculumUnit $unit */
            $unit = CurriculumUnit::query()->findOrFail($topic->curriculum_unit_id);

            /** @var Competency $competency */
            $competency = Competency::query()->findOrFail($competencyId);

            if ($competency->curriculum_id !== $unit->curriculum_id) {
                throw new DomainException(
                    'A topic can only be linked to a competency of its own curriculum version.'
                );
            }

            /** @var Curriculum $curriculum */
            $curriculum = Curriculum::query()->lockForUpdate()->findOrFail($unit->curriculum_id);

            if ($curriculum->isPublished()) {
                throw new DomainException(
                    'This curriculum version is published and locked; revise it '
                    .'to draft a new version before changing its links.'
                );
            }

            $exists = $topic->competencies()
                ->where('competencies.id', $competency->getKey())
                ->exists();

            if ($exists) {
                throw new DomainException('This topic is already linked to that competency.');
            }

            $topic->competencies()->attach($competency->getKey());

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Curriculum',
                auditableType: CurriculumTopic::class,
                auditableId: (int) $topic->getKey(),
                after: [
                    'linked_competency_id' => (int) $competency->getKey(),
                    'competency_code' => $competency->code,
                ],
                actor: $actor,
            );
        });
    }
}
