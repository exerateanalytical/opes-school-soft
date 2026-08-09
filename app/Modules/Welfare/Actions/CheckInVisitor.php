<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Welfare\Domain\VisitorHostType;
use App\Modules\Welfare\Domain\VisitorPermission;
use App\Modules\Welfare\Models\VisitorLog;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/plans/phase-10.md §4 (W4). Front desk checks a visitor in.
 *
 *  - The badge is a physical object: it cannot hang on two necks, so a
 *    badge_no already held by a CURRENTLY-checked-in visitor is refused.
 *    The pre-check runs under lock and the schema's NULL-unique
 *    active_badge_key backs it up against races.
 *  - A staff/student host must exist - read via DB::table, never through
 *    other modules' Models (ModuleBoundaryTest). An office visit is to a
 *    desk, not a person, so it must NOT name a host row.
 *  - The ID document reference is encrypted by the model cast and is
 *    deliberately absent from the audit entry (00-core 9.5: the audit log
 *    is plaintext and widely readable).
 */
final class CheckInVisitor
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    public function handle(
        string $visitorName,
        string $phone,
        ?string $idDocumentRef,
        string $purpose,
        VisitorHostType $hostType,
        ?int $hostId,
        string $badgeNo,
        Carbon $checkedInAt,
        Actor $actor,
        ?string $gatePassNo = null,
    ): VisitorLog {
        Gate::authorize(VisitorPermission::MANAGE);

        $visitorName = trim($visitorName);
        $phone = trim($phone);
        $purpose = trim($purpose);
        $badgeNo = trim($badgeNo);

        $errors = [];

        if ($visitorName === '') {
            $errors['visitor_name'] = 'A visitor cannot be checked in without a name.';
        }

        if ($phone === '') {
            $errors['phone'] = 'The gate register requires a contact phone number.';
        }

        if ($purpose === '') {
            $errors['purpose'] = 'Record why the visitor is here.';
        }

        if ($badgeNo === '') {
            $errors['badge_no'] = 'Issue a badge before checking the visitor in.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use (
            $visitorName, $phone, $idDocumentRef, $purpose,
            $hostType, $hostId, $badgeNo, $checkedInAt, $gatePassNo, $actor
        ): VisitorLog {
            if ($hostType === VisitorHostType::Office) {
                if ($hostId !== null) {
                    throw new DomainException(
                        'An office visit is to a desk, not a person; it cannot name a host row.'
                    );
                }
            } elseif ($hostId !== null) {
                $hostTable = $hostType === VisitorHostType::Staff ? 'users' : 'students';

                if (! DB::table($hostTable)->where('id', $hostId)->exists()) {
                    throw new DomainException(
                        'The named '.$hostType->value.' host does not exist.'
                    );
                }
            }

            // One badge, one neck: refuse a badge a visitor still on site is
            // wearing. lockForUpdate serialises two desks fighting over the
            // same badge; the schema's uq_visitor_logs_active_badge would
            // reject the loser anyway, but this reads as a sentence.
            $badgeInUse = VisitorLog::query()
                ->where('badge_no', $badgeNo)
                ->whereNull('checked_out_at')
                ->lockForUpdate()
                ->exists();

            if ($badgeInUse) {
                throw new DomainException(
                    'Badge '.$badgeNo.' is on the neck of a visitor who has not '
                    .'checked out yet; recover it or issue another.'
                );
            }

            $log = VisitorLog::query()->create([
                'visitor_name' => $visitorName,
                'phone' => $phone,
                'id_document_ref' => $idDocumentRef !== null && trim($idDocumentRef) !== ''
                    ? trim($idDocumentRef)
                    : null,
                'purpose' => $purpose,
                'host_type' => $hostType,
                'host_id' => $hostId,
                'badge_no' => $badgeNo,
                'checked_in_at' => $checkedInAt,
                'gate_pass_no' => $gatePassNo !== null && trim($gatePassNo) !== ''
                    ? trim($gatePassNo)
                    : null,
                'logged_by' => $actor->id,
            ]);

            // NO id_document_ref in the audit trail - it is plaintext at rest.
            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Welfare',
                auditableType: VisitorLog::class,
                auditableId: (int) $log->getKey(),
                after: [
                    'visitor_name' => $visitorName,
                    'purpose' => $purpose,
                    'host_type' => $hostType->value,
                    'host_id' => $hostId,
                    'badge_no' => $badgeNo,
                    'checked_in_at' => $checkedInAt->toDateTimeString(),
                ],
                actor: $actor,
            );

            return $log->refresh();
        });
    }
}
