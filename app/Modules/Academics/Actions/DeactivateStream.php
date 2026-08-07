<?php

declare(strict_types=1);

namespace App\Modules\Academics\Actions;

use App\Modules\Academics\Models\Stream;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Support\Audit\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class DeactivateStream
{
    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    public function handle(Stream $stream): Stream
    {
        Gate::authorize('academics.manage');

        $actor = auth()->user()?->toAuditActor() ?? Actor::system();

        // Nothing depends on streams yet - class groups and enrollment land in
        // a later chunk of Phase 1/2. When they do, this Action must gain a
        // dependency check (refuse to deactivate a stream that active class
        // groups or enrollments reference) before flipping the flag.
        return DB::transaction(function () use ($stream, $actor): Stream {
            $stream->update(['is_active' => false]);

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Academics',
                auditableType: Stream::class,
                auditableId: (int) $stream->getKey(),
                before: ['is_active' => true],
                after: ['is_active' => false],
                actor: $actor,
            );

            return $stream;
        });
    }
}
