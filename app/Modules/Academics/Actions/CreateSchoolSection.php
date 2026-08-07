<?php

declare(strict_types=1);

namespace App\Modules\Academics\Actions;

use App\Modules\Academics\Domain\EducationLevel;
use App\Modules\Academics\Domain\SubSystem;
use App\Modules\Academics\Domain\Track;
use App\Modules\Academics\Models\SchoolSection;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Support\Audit\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class CreateSchoolSection
{
    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    public function handle(
        EducationLevel $educationLevel,
        Track $track,
        SubSystem $subSystem,
        string $name,
        string $nameFr,
        string $matriculeFormat,
        int $displayOrder = 0,
    ): SchoolSection {
        Gate::authorize('academics.manage');

        // Gate + Actor instead of an Identity\Models\User parameter: 00-core
        // 6.2 forbids importing another module's Models, and the shared-kernel
        // Actor is the sanctioned way to attribute the audit entry.
        $actor = auth()->user()?->toAuditActor() ?? Actor::system();

        return DB::transaction(function () use (
            $educationLevel, $track, $subSystem, $name, $nameFr, $matriculeFormat, $displayOrder, $actor
        ): SchoolSection {
            $section = SchoolSection::query()->create([
                'education_level' => $educationLevel,
                'track' => $track,
                'sub_system' => $subSystem,
                'name' => $name,
                'name_fr' => $nameFr,
                'matricule_format' => $matriculeFormat,
                'display_order' => $displayOrder,
                'is_active' => true,
            ]);

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Academics',
                auditableType: SchoolSection::class,
                auditableId: (int) $section->getKey(),
                after: [
                    'education_level' => $educationLevel->value,
                    'track' => $track->value,
                    'sub_system' => $subSystem->value,
                    'name' => $name,
                ],
                actor: $actor,
            );

            return $section;
        });
    }
}
