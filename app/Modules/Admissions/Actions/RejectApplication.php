<?php

declare(strict_types=1);

namespace App\Modules\Admissions\Actions;

use App\Modules\Admissions\Domain\ApplicationStatus;
use App\Modules\Admissions\Models\AdmissionApplication;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Refuse an application and start the retention clock,
 * docs/specs/07-students.md 6.5.
 *
 * The row is NOT deleted and is not scheduled for deletion. 6.5 requires a
 * later job to PSEUDONYMISE it - names, DOB, photo, contacts,
 * `special_information` and every guardian row replaced with a tombstone -
 * while the application number, class applied for, decision and decision date
 * survive for admissions statistics. This Action's whole contribution to that
 * is stamping `purge_due_on` twelve months out; getting it wrong either
 * destroys the statistics or keeps a rejected child's medical note forever.
 */
final class RejectApplication
{
    /** 6.5: `purge_due_on = decided_at + 12 months`. */
    public const RETENTION_MONTHS = 12;

    public function handle(AdmissionApplication $application, string $reason): AdmissionApplication
    {
        Gate::authorize(Permission::AdmissionsManage->value);

        $trimmedReason = trim($reason);

        if ($trimmedReason === '') {
            // A rejection nobody has to explain is a rejection nobody can
            // appeal. The reason is shown to the family and is the only record
            // that survives pseudonymisation alongside the decision itself.
            throw ValidationException::withMessages([
                'decision_reason' => __('opes.admissions_screen.errors.reason_required'),
            ]);
        }

        $actor = $this->currentActor();

        return DB::transaction(function () use ($application, $trimmedReason, $actor): AdmissionApplication {
            /** @var AdmissionApplication|null $locked */
            $locked = AdmissionApplication::query()
                ->whereKey($application->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw new RuntimeException('The application disappeared between load and rejection.');
            }

            $previous = $locked->status;

            if (! $previous->isConvertible()) {
                // Only a live application can be refused. Rejecting an already
                // enrolled one would leave a student with no admission, and
                // re-rejecting a rejected one would silently reset a retention
                // clock that has already been running.
                throw ValidationException::withMessages([
                    'status' => __('opes.admissions_screen.errors.not_decidable'),
                ]);
            }

            $decidedAt = Carbon::now();

            $locked->status = ApplicationStatus::Rejected;
            $locked->decided_by = $actor->id;
            $locked->decided_at = $decidedAt;
            $locked->decision_reason = mb_substr($trimmedReason, 0, 255);
            $locked->purge_due_on = $decidedAt->copy()->addMonths(self::RETENTION_MONTHS)->startOfDay();
            $locked->updated_by = $actor->id;
            $locked->save();

            app(WriteAuditEntry::class)->handle(
                action: AuditAction::Updated,
                module: 'Admissions',
                auditableType: AdmissionApplication::class,
                auditableId: (int) $locked->getKey(),
                before: ['status' => $previous->value, 'purge_due_on' => null],
                after: [
                    'status' => ApplicationStatus::Rejected->value,
                    'decision_reason' => $locked->decision_reason,
                    'purge_due_on' => $locked->purge_due_on->toDateString(),
                ],
                actor: $actor,
            );

            return $locked;
        });
    }

    private function currentActor(): Actor
    {
        $actor = auth()->user()?->toAuditActor();

        if ($actor === null) {
            throw new RuntimeException('An admission cannot be rejected by an unauthenticated caller.');
        }

        return $actor;
    }
}
