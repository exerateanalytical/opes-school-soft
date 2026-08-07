<?php

declare(strict_types=1);

namespace App\Modules\Academics\Actions;

use App\Modules\Academics\Models\ClassLevel;
use App\Modules\Academics\Models\SchoolSection;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class CreateClassLevel
{
    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    /**
     * @throws DomainException when `code` already exists within the section.
     */
    public function handle(
        SchoolSection $section,
        string $code,
        string $name,
        string $nameFr,
        int $orderIndex,
        bool $isExamClass = false,
    ): ClassLevel {
        Gate::authorize('academics.manage');

        $actor = auth()->user()?->toAuditActor() ?? Actor::system();

        try {
            return DB::transaction(function () use (
                $section, $code, $name, $nameFr, $orderIndex, $isExamClass, $actor
            ): ClassLevel {
                // Uniqueness is the DATABASE's job - uq_class_level_section_code.
                // A SELECT-then-INSERT pre-check would race against a concurrent
                // insert; we let the constraint decide and translate the refusal
                // below into language an administrator can act on.
                $level = ClassLevel::query()->create([
                    'school_section_id' => $section->getKey(),
                    'code' => $code,
                    'name' => $name,
                    'name_fr' => $nameFr,
                    'order_index' => $orderIndex,
                    'is_exam_class' => $isExamClass,
                ]);

                $this->audit->handle(
                    action: AuditAction::Created,
                    module: 'Academics',
                    auditableType: ClassLevel::class,
                    auditableId: (int) $level->getKey(),
                    after: [
                        'school_section_id' => $section->getKey(),
                        'code' => $code,
                        'name' => $name,
                        'order_index' => $orderIndex,
                        'is_exam_class' => $isExamClass,
                    ],
                    actor: $actor,
                );

                return $level;
            });
        } catch (UniqueConstraintViolationException) {
            throw new DomainException(
                "A class level with code '{$code}' already exists in section '{$section->name}'."
            );
        }
    }
}
