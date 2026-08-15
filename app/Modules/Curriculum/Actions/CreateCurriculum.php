<?php

declare(strict_types=1);

namespace App\Modules\Curriculum\Actions;

use App\Modules\Academics\Domain\SubSystem;
use App\Modules\Curriculum\Domain\CurriculumPermission;
use App\Modules\Curriculum\Domain\CurriculumStatus;
use App\Modules\Curriculum\Models\Curriculum;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Creates the FIRST version (a draft) of a curriculum for a (subject,
 * class level, sub-system, academic year) identity.
 *
 * Only version 1 is ever created here: once any version exists for the
 * identity, further versions come exclusively from ReviseCurriculum, so
 * the version chain has exactly one root and no gaps.
 *
 * Cross-module existence checks (subjects, class_levels, academic_years)
 * go through DB::table only - never another module's Models
 * (ModuleBoundaryTest).
 */
final class CreateCurriculum
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    /**
     * @param  array{subject_id: int, class_level_id: int, academic_year_id: int, sub_system: string, title: string, description?: string|null}  $data
     */
    public function handle(array $data, Actor $actor): Curriculum
    {
        Gate::authorize(CurriculumPermission::MANAGE);

        if (trim($data['title']) === '') {
            throw ValidationException::withMessages([
                'title' => 'A curriculum requires a title.',
            ]);
        }

        $subSystem = SubSystem::tryFrom($data['sub_system']);

        if ($subSystem === null) {
            throw ValidationException::withMessages([
                'sub_system' => 'Unknown sub-system.',
            ]);
        }

        foreach ([
            'subject_id' => 'subjects',
            'class_level_id' => 'class_levels',
            'academic_year_id' => 'academic_years',
        ] as $field => $table) {
            if (! DB::table($table)->where('id', $data[$field])->exists()) {
                throw ValidationException::withMessages([
                    $field => 'The referenced record does not exist.',
                ]);
            }
        }

        return DB::transaction(function () use ($data, $subSystem, $actor): Curriculum {
            $identityExists = Curriculum::query()
                ->where('subject_id', $data['subject_id'])
                ->where('class_level_id', $data['class_level_id'])
                ->where('academic_year_id', $data['academic_year_id'])
                ->where('sub_system', $subSystem->value)
                ->lockForUpdate()
                ->exists();

            if ($identityExists) {
                throw new DomainException(
                    'A curriculum already exists for this subject, class level, '
                    .'sub-system and academic year; revise it to produce a new version.'
                );
            }

            $curriculum = Curriculum::query()->create([
                'subject_id' => $data['subject_id'],
                'class_level_id' => $data['class_level_id'],
                'academic_year_id' => $data['academic_year_id'],
                'sub_system' => $subSystem->value,
                'title' => trim($data['title']),
                'description' => isset($data['description']) && trim((string) $data['description']) !== ''
                    ? trim((string) $data['description'])
                    : null,
                'version' => 1,
                'status' => CurriculumStatus::Draft->value,
            ]);

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Curriculum',
                auditableType: Curriculum::class,
                auditableId: (int) $curriculum->getKey(),
                after: [
                    'subject_id' => $curriculum->subject_id,
                    'class_level_id' => $curriculum->class_level_id,
                    'academic_year_id' => $curriculum->academic_year_id,
                    'sub_system' => $curriculum->sub_system->value,
                    'title' => $curriculum->title,
                    'version' => $curriculum->version,
                    'status' => $curriculum->status->value,
                ],
                actor: $actor,
            );

            return $curriculum->refresh();
        });
    }
}
