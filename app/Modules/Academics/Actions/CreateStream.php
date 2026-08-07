<?php

declare(strict_types=1);

namespace App\Modules\Academics\Actions;

use App\Modules\Academics\Models\SchoolSection;
use App\Modules\Academics\Models\Stream;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Support\Audit\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class CreateStream
{
    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    /**
     * @param  list<string>  $subjectBasket  Drives the ranking cohort (00-core 8) -
     *                                       students are ranked only against peers
     *                                       sharing this basket.
     */
    public function handle(
        SchoolSection $section,
        string $code,
        string $name,
        string $nameFr,
        array $subjectBasket,
    ): Stream {
        Gate::authorize('academics.manage');

        $actor = auth()->user()?->toAuditActor() ?? Actor::system();

        return DB::transaction(function () use (
            $section, $code, $name, $nameFr, $subjectBasket, $actor
        ): Stream {
            $stream = Stream::query()->create([
                'school_section_id' => $section->getKey(),
                'code' => $code,
                'name' => $name,
                'name_fr' => $nameFr,
                'subject_basket' => $subjectBasket,
                'is_active' => true,
            ]);

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Academics',
                auditableType: Stream::class,
                auditableId: (int) $stream->getKey(),
                after: [
                    'school_section_id' => $section->getKey(),
                    'code' => $code,
                    'name' => $name,
                    'subject_basket' => $subjectBasket,
                ],
                actor: $actor,
            );

            return $stream;
        });
    }
}
