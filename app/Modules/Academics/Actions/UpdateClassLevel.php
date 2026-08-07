<?php

declare(strict_types=1);

namespace App\Modules\Academics\Actions;

use App\Modules\Academics\Models\ClassLevel;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class UpdateClassLevel
{
    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    /**
     * @throws DomainException when the new `code` collides within the section.
     */
    public function handle(
        ClassLevel $level,
        string $code,
        string $name,
        string $nameFr,
        int $orderIndex,
        bool $isExamClass,
    ): ClassLevel {
        Gate::authorize('academics.manage');

        $actor = auth()->user()?->toAuditActor() ?? Actor::system();

        $before = [
            'code' => $level->code,
            'name' => $level->name,
            'name_fr' => $level->name_fr,
            'order_index' => $level->order_index,
            'is_exam_class' => $level->is_exam_class,
        ];

        try {
            return DB::transaction(function () use (
                $level, $code, $name, $nameFr, $orderIndex, $isExamClass, $actor, $before
            ): ClassLevel {
                $level->update([
                    'code' => $code,
                    'name' => $name,
                    'name_fr' => $nameFr,
                    'order_index' => $orderIndex,
                    'is_exam_class' => $isExamClass,
                ]);

                $this->audit->handle(
                    action: AuditAction::Updated,
                    module: 'Academics',
                    auditableType: ClassLevel::class,
                    auditableId: (int) $level->getKey(),
                    before: $before,
                    after: [
                        'code' => $code,
                        'name' => $name,
                        'name_fr' => $nameFr,
                        'order_index' => $orderIndex,
                        'is_exam_class' => $isExamClass,
                    ],
                    actor: $actor,
                );

                return $level;
            });
        } catch (UniqueConstraintViolationException) {
            throw new DomainException(
                "A class level with code '{$code}' already exists in this section."
            );
        }
    }
}
