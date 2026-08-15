<?php

declare(strict_types=1);

namespace App\Modules\Alumni\Actions;

use App\Modules\Alumni\Models\AlumnusRecord;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The mutable half of an AlumnusRecord: where the graduate is now and how
 * to reach them. The frozen half (graduation year, the label-at-time
 * names) is deliberately NOT writable here - history does not update.
 */
final class UpdateAlumnusContact
{
    public const PERMISSION = Permission::AlumniManage->value;

    public function __construct(private readonly WriteAuditEntry $audit) {}

    /**
     * @param array{
     *     current_occupation?: string|null,
     *     current_organisation?: string|null,
     *     contact_email?: string|null,
     *     contact_phone?: string|null,
     *     notes?: string|null,
     * } $data
     */
    public function handle(int $alumnusRecordId, array $data, Actor $actor): AlumnusRecord
    {
        Gate::authorize(self::PERMISSION);

        return DB::transaction(function () use ($alumnusRecordId, $data, $actor): AlumnusRecord {
            /** @var AlumnusRecord $record */
            $record = AlumnusRecord::query()->lockForUpdate()->findOrFail($alumnusRecordId);

            $writable = [
                'current_occupation', 'current_organisation',
                'contact_email', 'contact_phone', 'notes',
            ];

            $before = [];
            $after = [];

            foreach ($writable as $field) {
                if (! array_key_exists($field, $data)) {
                    continue;
                }

                $value = $data[$field];
                $value = is_string($value) && trim($value) === '' ? null : $value;

                if ($record->getAttribute($field) === $value) {
                    continue;
                }

                $before[$field] = $record->getAttribute($field);
                $after[$field] = $value;
                $record->setAttribute($field, $value);
            }

            if ($after === []) {
                return $record;
            }

            $record->updated_by = $actor->id;
            $record->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Alumni',
                auditableType: AlumnusRecord::class,
                auditableId: (int) $record->getKey(),
                before: $before,
                after: $after,
                actor: $actor,
            );

            return $record;
        });
    }
}
