<?php

declare(strict_types=1);

namespace App\Modules\Curriculum\Actions;

use App\Modules\Curriculum\Domain\CurriculumPermission;
use App\Modules\Curriculum\Domain\CurriculumStatus;
use App\Modules\Curriculum\Models\Curriculum;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Publishes a draft: stamps published_at / published_by and locks the
 * version forever. One-way - there is no unpublish, because teaching plans
 * and assessments may already reference the version; superseding it is
 * ReviseCurriculum's job.
 *
 * An empty curriculum (no units, or units with no topics at all) cannot be
 * published: a programme of study with nothing in it is a data-entry
 * accident, not a programme.
 */
final class PublishCurriculum
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    public function handle(int $curriculumId, Actor $actor): Curriculum
    {
        Gate::authorize(CurriculumPermission::MANAGE);

        return DB::transaction(function () use ($curriculumId, $actor): Curriculum {
            /** @var Curriculum $curriculum */
            $curriculum = Curriculum::query()->lockForUpdate()->findOrFail($curriculumId);

            if ($curriculum->isPublished()) {
                throw new DomainException('This curriculum version is already published.');
            }

            $unitCount = (int) $curriculum->units()->count();

            if ($unitCount === 0) {
                throw new DomainException(
                    'An empty curriculum cannot be published; add at least one unit first.'
                );
            }

            $topicCount = (int) DB::table('curriculum_topics')
                ->whereIn(
                    'curriculum_unit_id',
                    DB::table('curriculum_units')->where('curriculum_id', $curriculum->getKey())->select('id'),
                )
                ->count();

            if ($topicCount === 0) {
                throw new DomainException(
                    'A curriculum without any topic cannot be published; add topics to its units first.'
                );
            }

            $curriculum->forceFill([
                'status' => CurriculumStatus::Published->value,
                'published_at' => Carbon::now(),
                'published_by' => $actor->id,
            ])->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Curriculum',
                auditableType: Curriculum::class,
                auditableId: (int) $curriculum->getKey(),
                before: ['status' => CurriculumStatus::Draft->value],
                after: [
                    'status' => CurriculumStatus::Published->value,
                    'version' => $curriculum->version,
                    'units' => $unitCount,
                    'topics' => $topicCount,
                ],
                actor: $actor,
            );

            return $curriculum->refresh();
        });
    }
}
