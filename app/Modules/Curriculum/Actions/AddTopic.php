<?php

declare(strict_types=1);

namespace App\Modules\Curriculum\Actions;

use App\Modules\Curriculum\Domain\CurriculumPermission;
use App\Modules\Curriculum\Models\Curriculum;
use App\Modules\Curriculum\Models\CurriculumTopic;
use App\Modules\Curriculum\Models\CurriculumUnit;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Appends a topic to a unit of a DRAFT curriculum, sequence max + 1 under
 * the curriculum row lock (same concurrency posture as AddUnit).
 */
final class AddTopic
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    /**
     * @param  array{title: string, learning_outcome?: string|null}  $data
     */
    public function handle(int $unitId, array $data, Actor $actor): CurriculumTopic
    {
        Gate::authorize(CurriculumPermission::MANAGE);

        if (trim($data['title']) === '') {
            throw ValidationException::withMessages([
                'title' => 'A topic requires a title.',
            ]);
        }

        return DB::transaction(function () use ($unitId, $data, $actor): CurriculumTopic {
            /** @var CurriculumUnit $unit */
            $unit = CurriculumUnit::query()->findOrFail($unitId);

            /** @var Curriculum $curriculum */
            $curriculum = Curriculum::query()->lockForUpdate()->findOrFail($unit->curriculum_id);

            if ($curriculum->isPublished()) {
                throw new DomainException(
                    'This curriculum version is published and locked; revise it '
                    .'to draft a new version before changing its structure.'
                );
            }

            $nextSequence = 1 + (int) CurriculumTopic::query()
                ->where('curriculum_unit_id', $unit->getKey())
                ->max('sequence');

            $topic = CurriculumTopic::query()->create([
                'curriculum_unit_id' => (int) $unit->getKey(),
                'title' => trim($data['title']),
                'learning_outcome' => isset($data['learning_outcome']) && trim((string) $data['learning_outcome']) !== ''
                    ? trim((string) $data['learning_outcome'])
                    : null,
                'sequence' => $nextSequence,
            ]);

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Curriculum',
                auditableType: CurriculumTopic::class,
                auditableId: (int) $topic->getKey(),
                after: [
                    'curriculum_unit_id' => $topic->curriculum_unit_id,
                    'title' => $topic->title,
                    'sequence' => $topic->sequence,
                ],
                actor: $actor,
            );

            return $topic;
        });
    }
}
