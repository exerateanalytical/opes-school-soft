<?php

declare(strict_types=1);

namespace App\Modules\Fees\Actions;

use App\Modules\Accounting\Actions\PostFromEvent;
use App\Modules\Accounting\Domain\PostingEvent;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/08-operations.md §6.2 step 7 - "credit balances carry forward
 * to the new year's student account" - as a LEDGER EVENT, for ONE student.
 *
 * This is the Fees-module door the rollover wizard's CarryBalancesStep calls
 * per student in credit. The ledger consequence goes through
 * Accounting\Actions\PostFromEvent (`receivable.reclassified`, 04-fees §12.6:
 * Dr 4111 / Cr 4191 "Clients, avances et acomptes reçus", both lines
 * partnered to the student) - the SINGLE posting path; this Action writes no
 * journal table itself, ever.
 *
 * NON-COMPENSATION (04-fees A5/C9): the Action is deliberately shaped so it
 * CANNOT net across students - it takes one student id and produces one
 * journal entry for that student's own credit. A caller with fifty students
 * in credit makes fifty calls and gets fifty entries, exactly like the §15.9
 * worked example ("one line pair per student in credit; there is no
 * aggregated 'net credit balances' line").
 *
 * Operationally the credit stays where it lives: on the original payments'
 * `unallocated_amount` (§12.3's derived cache), which AllocatePayment already
 * consumes against future invoices regardless of year. What this Action adds
 * is the explicit year-boundary recognition the rollover requires - the
 * amount, verified under lock, presented as a 4191 advance - plus the audit
 * trail. Consumption-time reversal of the 4191 presentation belongs to the
 * (future) RunReceivableReclassification engine of §12.6.5, not here.
 */
final class CarryForwardStudentCredit
{
    public const PERMISSION = Permission::FeeCollect->value;

    public function __construct(
        private readonly PostFromEvent $post,
        private readonly WriteAuditEntry $audit,
    ) {}

    /**
     * @return array{amount: int, journal_entry_id: int}
     */
    public function handle(
        int $studentId,
        int $fromAcademicYearId,
        int $toAcademicYearId,
        string $postingDate,
        Actor $actor,
        ?string $reference = null,
    ): array {
        Gate::authorize(self::PERMISSION);

        if ($fromAcademicYearId === $toAcademicYearId) {
            throw new DomainException('A credit cannot be carried into the year it already belongs to.');
        }

        return DB::transaction(function () use ($studentId, $fromAcademicYearId, $toAcademicYearId, $postingDate, $actor, $reference): array {
            $fromYear = DB::table('academic_years')->where('id', $fromAcademicYearId)->first();
            $toYear = DB::table('academic_years')->where('id', $toAcademicYearId)->first();

            if ($fromYear === null || $toYear === null) {
                throw new DomainException('Both academic years must exist before a credit is carried between them.');
            }

            // §12.3: the unallocated cache is maintained only inside the
            // §11.2 lock, so it is re-read here UNDER lock, ascending id -
            // the same order every other Fees Action locks payments in.
            // Bounced and voided payments contribute nothing (§5).
            /** @var list<object{id: int|string, unallocated_amount: int|string}> $payments */
            $payments = DB::table('payments as p')
                ->where('p.student_id', $studentId)
                ->where('p.academic_year_id', $fromAcademicYearId)
                ->where('p.clearing_state', '<>', 'bounced')
                ->where('p.unallocated_amount', '>', 0)
                ->whereNotExists(function ($query): void {
                    $query->select(DB::raw(1))
                        ->from('payment_voids as v')
                        ->whereColumn('v.payment_id', 'p.id')
                        ->where('v.status', 'confirmed');
                })
                ->orderBy('p.id')
                ->lockForUpdate()
                ->get(['p.id', 'p.unallocated_amount'])
                ->all();

            $credit = 0;

            foreach ($payments as $payment) {
                $credit += (int) $payment->unallocated_amount;
            }

            if ($credit <= 0) {
                throw new DomainException(
                    "Student {$studentId} has no unallocated credit in the outgoing academic year; there is nothing to carry forward."
                );
            }

            $receivableAccountId = DB::table('chart_of_accounts')->where('code', '4111')->value('id');
            $advancesAccountId = DB::table('chart_of_accounts')->where('code', '4191')->value('id');

            if (! is_numeric($receivableAccountId) || ! is_numeric($advancesAccountId)) {
                throw new DomainException(
                    'Accounts 4111 (Clients) and 4191 (Clients, avances et acomptes reçus) must both exist in the chart of accounts before a credit can be carried.'
                );
            }

            /** @var object{code: string} $toYearRow */
            $toYearRow = $toYear;
            $reference ??= sprintf('CARRY/%s/%d', (string) $toYearRow->code, $studentId);

            $entry = $this->post->handle(
                PostingEvent::ReceivableReclassified->value,
                [
                    'adjustment' => [
                        'amount' => $credit,
                        'reference' => $reference,
                        'partner' => ['type' => 'student', 'id' => $studentId],
                        'receivable_account_id' => (int) $receivableAccountId,
                        'counterpart_account_id' => (int) $advancesAccountId,
                    ],
                ],
                $postingDate,
                $actor,
                $reference,
            );

            // No Accounting model class is named here - the module boundary
            // is absolute (tests/Architecture/ModuleBoundaryTest.php); the
            // posted entry is referenced by id in the audit payload.
            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Fees',
                after: [
                    'journal_entry_id' => (int) $entry->getKey(),
                    'event' => PostingEvent::ReceivableReclassified->value,
                    'student_id' => $studentId,
                    'academic_year_from_id' => $fromAcademicYearId,
                    'academic_year_to_id' => $toAcademicYearId,
                    'amount' => $credit,
                    'reference' => $reference,
                ],
                actor: $actor,
            );

            return [
                'amount' => $credit,
                'journal_entry_id' => (int) $entry->getKey(),
            ];
        });
    }
}
