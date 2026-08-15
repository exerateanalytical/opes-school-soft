<?php

declare(strict_types=1);

namespace App\Modules\Alumni\Actions;

use App\Modules\Alumni\Domain\EngagementType;
use App\Modules\Alumni\Models\AlumniEngagement;
use App\Modules\Alumni\Models\AlumnusRecord;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Appends one touch point to an alumnus's interaction log. Append-only:
 * there is no edit or delete door, so the log reads as what actually
 * happened, in the order it was recorded.
 */
final class RecordEngagement
{
    public const PERMISSION = Permission::AlumniManage->value;

    public function __construct(private readonly WriteAuditEntry $audit) {}

    /**
     * @param array{type: string, engaged_on: string, note: string} $data
     */
    public function handle(int $alumnusRecordId, array $data, Actor $actor): AlumniEngagement
    {
        Gate::authorize(self::PERMISSION);

        $type = EngagementType::from($data['type']);
        $engagedOn = Carbon::parse($data['engaged_on']);
        $note = trim($data['note']);

        if ($note === '') {
            throw new DomainException(
                'An engagement needs a note - a bare date and type tells the next reader nothing.'
            );
        }

        if ($engagedOn->isFuture()) {
            throw new DomainException(
                'An engagement is a record of something that happened; its date cannot be in the future.'
            );
        }

        return DB::transaction(function () use ($alumnusRecordId, $type, $engagedOn, $note, $actor): AlumniEngagement {
            // findOrFail inside the transaction: the engagement must attach to
            // a record that exists at write time.
            /** @var AlumnusRecord $record */
            $record = AlumnusRecord::query()->lockForUpdate()->findOrFail($alumnusRecordId);

            $engagement = AlumniEngagement::query()->create([
                'alumnus_record_id' => (int) $record->getKey(),
                'type' => $type,
                'engaged_on' => $engagedOn->toDateString(),
                'note' => $note,
                'recorded_by' => $actor->id,
            ]);

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Alumni',
                auditableType: AlumniEngagement::class,
                auditableId: (int) $engagement->getKey(),
                after: [
                    'alumnus_record_id' => (int) $record->getKey(),
                    'type' => $type->value,
                    'engaged_on' => $engagedOn->toDateString(),
                ],
                actor: $actor,
            );

            return $engagement;
        });
    }
}
