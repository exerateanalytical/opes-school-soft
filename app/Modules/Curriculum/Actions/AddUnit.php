<?php

declare(strict_types=1);

namespace App\Modules\Curriculum\Actions;

use App\Modules\Curriculum\Domain\CurriculumPermission;
use App\Modules\Curriculum\Models\Curriculum;
use App\Modules\Curriculum\Models\CurriculumUnit;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Appends a unit to a DRAFT curriculum. Sequence is assigned here as
 * max + 1 under the row lock, so UNIQUE(curriculum_id, sequence) can never
 * be violated by two concurrent adds.
 *
 * Refused on a published curriculum: publication locks the version, and a
 * change is a new version via ReviseCurriculum.
 */
final class AddUnit
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    /**
     * @param  array{title: string, description?: string|null}  $data
     */
    public function handle(int $curriculumId, array $data, Actor $actor): CurriculumUnit
    {
        Gate::authorize(CurriculumPermission::MANAGE);

        if (trim($data['title']) === '') {
            throw ValidationException::withMessages([
                'title' => 'A unit requires a title.',
            ]);
        }

        return DB::transaction(function () use ($curriculumId, $data, $actor): CurriculumUnit {
            /** @var Curriculum $curriculum */
            $curriculum = Curriculum::query()->lockForUpdate()->findOrFail($curriculumId);

            if ($curriculum->isPublished()) {
                throw new DomainException(
                    'This curriculum version is published and locked; revise it '
                    .'to draft a new version before changing its structure.'
                );
            }

            $nextSequence = 1 + (int) CurriculumUnit::query()
                ->where('curriculum_id', $curriculum->getKey())
                ->max('sequence');

            $unit = CurriculumUnit::query()->create([
                'curriculum_id' => (int) $curriculum->getKey(),
                'title' => trim($data['title']),
                'description' => isset($data['description']) && trim((string) $data['description']) !== ''
                    ? trim((string) $data['description'])
                    : null,
                'sequence' => $nextSequence,
            ]);

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Curriculum',
                auditableType: CurriculumUnit::class,
                auditableId: (int) $unit->getKey(),
                after: [
                    'curriculum_id' => $unit->curriculum_id,
                    'title' => $unit->title,
                    'sequence' => $unit->sequence,
                ],
                actor: $actor,
            );

            return $unit;
        });
    }
}
