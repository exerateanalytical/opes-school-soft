<?php

declare(strict_types=1);

namespace App\Modules\Alumni\Actions;

use App\Modules\Alumni\Models\AlumnusRecord;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * One-way. There is deliberately no unmark door: a wrongly filtered list
 * must never be "fixed" by resurrecting someone, and if the flag was set
 * on the wrong record the correction is a human conversation and a
 * SuperAdmin data fix, not a routine screen action. The refusal below is
 * what makes the one-way contract testable rather than aspirational.
 */
final class MarkDeceased
{
    public const PERMISSION = Permission::AlumniManage->value;

    public function __construct(private readonly WriteAuditEntry $audit) {}

    public function handle(int $alumnusRecordId, Actor $actor): AlumnusRecord
    {
        Gate::authorize(self::PERMISSION);

        return DB::transaction(function () use ($alumnusRecordId, $actor): AlumnusRecord {
            /** @var AlumnusRecord $record */
            $record = AlumnusRecord::query()->lockForUpdate()->findOrFail($alumnusRecordId);

            if ($record->is_deceased) {
                throw new DomainException(
                    'This alumnus is already marked deceased; the flag is one-way.'
                );
            }

            $record->is_deceased = true;
            $record->updated_by = $actor->id;
            $record->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Alumni',
                auditableType: AlumnusRecord::class,
                auditableId: (int) $record->getKey(),
                before: ['is_deceased' => false],
                after: ['is_deceased' => true],
                actor: $actor,
            );

            return $record;
        });
    }
}
