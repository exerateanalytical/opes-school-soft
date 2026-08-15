<?php

declare(strict_types=1);

namespace App\Modules\Curriculum\Actions;

use App\Modules\Curriculum\Domain\CurriculumPermission;
use App\Modules\Curriculum\Models\Competency;
use App\Modules\Curriculum\Models\Curriculum;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Adds a competency (code + descriptor) to a DRAFT curriculum. Codes are
 * unique within the version; the same code recurring across versions is
 * expected (ReviseCurriculum clones the list).
 */
final class AddCompetency
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    /**
     * @param  array{code: string, descriptor: string}  $data
     */
    public function handle(int $curriculumId, array $data, Actor $actor): Competency
    {
        Gate::authorize(CurriculumPermission::MANAGE);

        foreach (['code', 'descriptor'] as $field) {
            if (trim($data[$field]) === '') {
                throw ValidationException::withMessages([
                    $field => 'A competency requires a code and a descriptor.',
                ]);
            }
        }

        return DB::transaction(function () use ($curriculumId, $data, $actor): Competency {
            /** @var Curriculum $curriculum */
            $curriculum = Curriculum::query()->lockForUpdate()->findOrFail($curriculumId);

            if ($curriculum->isPublished()) {
                throw new DomainException(
                    'This curriculum version is published and locked; revise it '
                    .'to draft a new version before changing its competencies.'
                );
            }

            $clash = Competency::query()
                ->where('curriculum_id', $curriculum->getKey())
                ->where('code', trim($data['code']))
                ->exists();

            if ($clash) {
                throw ValidationException::withMessages([
                    'code' => 'A competency with this code already exists on this curriculum.',
                ]);
            }

            $competency = Competency::query()->create([
                'curriculum_id' => (int) $curriculum->getKey(),
                'code' => trim($data['code']),
                'descriptor' => trim($data['descriptor']),
            ]);

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Curriculum',
                auditableType: Competency::class,
                auditableId: (int) $competency->getKey(),
                after: [
                    'curriculum_id' => $competency->curriculum_id,
                    'code' => $competency->code,
                    'descriptor' => $competency->descriptor,
                ],
                actor: $actor,
            );

            return $competency;
        });
    }
}
