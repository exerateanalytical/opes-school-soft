<?php

declare(strict_types=1);

namespace App\Modules\Library\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Library\Domain\FineCalculator;
use App\Modules\Library\Domain\FineStatus;
use App\Modules\Library\Domain\FineType;
use App\Modules\Library\Domain\IssueStatus;
use App\Modules\Library\Domain\LibraryPermission;
use App\Modules\Library\Models\LibraryFine;
use App\Modules\Library\Models\LibraryIssue;
use App\Modules\Library\Models\LibraryMember;
use App\Modules\Library\Models\MembershipClass;
use App\Support\Audit\Actor;
use App\Support\Sequence\SequenceAllocator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * 06-assets-stores.md §10.5 - the nightly, IDEMPOTENT overdue accrual.
 *
 * The fine is an ENTITLEMENT, recomputed in full each run (FineCalculator)
 * and written onto the single per-issue overdue fine row
 * (`uq_overdue_fine_issue`) - never a day appended per night. A job that
 * runs five times in one day, or is missed for a week, still lands on the
 * correct figure (acceptance 11). Days the library was closed per the
 * school calendar are excluded; the cap is the book's replacement cost
 * when the membership class says so.
 *
 * Only fines still in `assessed` adjust: once LEVIED (invoiced through
 * Fees, §10.7) the assessment is frozen - the debt lives in Fees.
 */
final class AccrueOverdueFines
{
    public function __construct(
        private readonly SequenceAllocator $sequence,
        private readonly WriteAuditEntry $audit,
    ) {}

    /**
     * @return array{examined: int, created: int, adjusted: int}
     */
    public function handle(string $asOf, Actor $actor): array
    {
        if ($actor->id !== null) {
            Gate::authorize(LibraryPermission::CIRCULATE);
        }

        return DB::transaction(function () use ($asOf, $actor): array {
            /** @var list<LibraryIssue> $issues */
            $issues = LibraryIssue::query()
                ->where('status', IssueStatus::Overdue->value)
                ->lockForUpdate()
                ->get()
                ->all();

            $examined = 0;
            $created = 0;
            $adjusted = 0;

            foreach ($issues as $issue) {
                $examined++;

                /** @var LibraryMember $member */
                $member = LibraryMember::query()->findOrFail($issue->library_member_id);

                /** @var MembershipClass $class */
                $class = MembershipClass::query()->findOrFail($member->membership_class_id);

                if ($class->fine_per_day <= 0) {
                    continue; // This class accrues no overdue fines.
                }

                $replacementCost = (int) DB::table('book_copies')
                    ->join('books', 'books.id', '=', 'book_copies.book_id')
                    ->where('book_copies.id', $issue->book_copy_id)
                    ->value('books.replacement_cost');

                $entitlement = FineCalculator::overdueEntitlement(
                    dueOn: $issue->due_on,
                    asOf: $asOf,
                    graceDays: $class->fine_grace_days,
                    finePerDay: $class->fine_per_day,
                    capPolicy: $class->fine_cap_policy,
                    replacementCost: $replacementCost,
                    closedDates: $this->closedDates($issue->due_on, $asOf),
                );

                /** @var LibraryFine|null $fine */
                $fine = LibraryFine::query()
                    ->where('library_issue_id', $issue->getKey())
                    ->where('fine_type', FineType::Overdue->value)
                    ->lockForUpdate()
                    ->first();

                if ($fine === null) {
                    if ($entitlement['amount'] <= 0) {
                        continue; // Inside grace - nothing to assess yet.
                    }

                    LibraryFine::query()->create([
                        'fine_no' => sprintf(
                            'FIN/%s/%06d',
                            Carbon::parse($asOf)->format('Y'),
                            $this->sequence->allocate('library.fine_no'),
                        ),
                        'library_issue_id' => (int) $issue->getKey(),
                        'library_member_id' => (int) $member->getKey(),
                        'student_id' => $member->student_id,
                        'fine_type' => FineType::Overdue,
                        'assessed_on' => $asOf,
                        'days_overdue' => $entitlement['days_overdue'],
                        'amount' => $entitlement['amount'],
                        'status' => FineStatus::Assessed,
                        // §10.6: derived from member_type NOW and snapshotted.
                        'settlement_route' => $member->member_type->settlementRoute(),
                    ]);

                    $created++;

                    continue;
                }

                if ($fine->status !== FineStatus::Assessed) {
                    continue; // Levied / waived: the assessment is frozen.
                }

                if ($fine->amount === $entitlement['amount']
                    && $fine->days_overdue === $entitlement['days_overdue']) {
                    continue; // Already at the entitlement - idempotent no-op.
                }

                $fine->forceFill([
                    'days_overdue' => $entitlement['days_overdue'],
                    'amount' => $entitlement['amount'],
                    'assessed_on' => $asOf,
                ])->save();

                $adjusted++;
            }

            if ($created > 0 || $adjusted > 0) {
                $this->audit->handle(
                    action: AuditAction::Updated,
                    module: 'Library',
                    auditableType: LibraryFine::class,
                    auditableId: null,
                    after: ['as_of' => $asOf, 'created' => $created, 'adjusted' => $adjusted],
                    actor: $actor,
                );
            }

            return ['examined' => $examined, 'created' => $created, 'adjusted' => $adjusted];
        });
    }

    /**
     * Dates the library was closed per the school calendar (§10.5): every
     * calendar row in the window whose day type is not a teaching/exam
     * day. A missing calendar excludes nothing - days count.
     *
     * @return list<string>
     */
    private function closedDates(string $dueOn, string $asOf): array
    {
        /** @var list<string> $dates */
        $dates = DB::table('school_calendar_days')
            ->where('date', '>', $dueOn)
            ->where('date', '<=', $asOf)
            ->whereNotIn('day_type', ['teaching', 'exam'])
            ->distinct()
            ->pluck('date')
            ->map(static fn ($d): string => Carbon::parse((string) $d)->toDateString())
            ->all();

        return $dates;
    }
}
