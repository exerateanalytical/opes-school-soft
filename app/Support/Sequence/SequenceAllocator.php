<?php

declare(strict_types=1);

namespace App\Support\Sequence;

use Illuminate\Support\Facades\DB;

/**
 * The only issuer of generated numbers (docs/specs/00-core.md 12).
 *
 * Lives in app/Support/ rather than a module because matricule (Students),
 * admission_no (Admissions), receipt_no (Fees) and piece_no (Accounting) all
 * need it, and a module may not import another module's code (00-core 6.2).
 *
 * Two obligations, both served by the same row lock:
 *
 *  - **Gapless** series (`JournalEntry.piece_no`) must have no holes, so the
 *    number is allocated at the moment of posting inside the same transaction
 *    as the insert. A rollback then consumes nothing, because the UPDATE to
 *    next_value rolls back with it. That is exactly what makes gaplessness
 *    real rather than aspirational.
 *  - **Gaps-permitted** series (matricule, admission_no, receipt_no) need only
 *    atomicity; they get the same treatment because there is no reason to have
 *    two mechanisms.
 *
 * `max(column) + 1` is never acceptable and is the bug this class exists to
 * make unavailable: two callers read the same max and produce the same string.
 */
final class SequenceAllocator
{
    /**
     * Reserve `$count` consecutive numbers from `$series` and return the FIRST
     * of them; the caller owns `[return, return + count - 1]`.
     *
     * MUST be called inside the caller's transaction - see
     * SequenceOutsideTransactionException for why that is enforced rather than
     * documented.
     *
     * @param  string  $series  fully-scoped key, e.g. `matricule.2026.SEC1`.
     *                          The scope is part of the key on purpose: 00-core 12
     *                          requires uniqueness scope to be stated per series
     *                          instead of being implied by the caller.
     */
    public function allocate(string $series, int $count = 1): int
    {
        if ($count < 1) {
            throw new \InvalidArgumentException('A sequence allocation must ask for at least one number.');
        }

        if (DB::transactionLevel() < 1) {
            throw SequenceOutsideTransactionException::forSeries($series);
        }

        $row = $this->lockRow($series);

        if ($row === null) {
            // First use of this series. insertOrIgnore rather than insert:
            // a concurrent first-user blocks on the unique key and then finds
            // its own insert redundant. Raising and catching a duplicate-key
            // exception inside someone else's transaction is avoidable noise.
            DB::table('sequences')->insertOrIgnore([
                'series' => $series,
                'next_value' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Re-read UNDER THE LOCK. The row is guaranteed to exist now, but
            // it may have been created - and already advanced - by the racing
            // caller, so its next_value must be read, never assumed to be 1.
            $row = $this->lockRow($series);
        }

        if ($row === null) {
            // Unreachable in practice; the alternative is returning a number
            // nothing guarantees is unique, which is worse than failing.
            throw new \RuntimeException("Sequence [{$series}] could not be created or locked.");
        }

        $first = (int) $row->next_value;

        DB::table('sequences')
            ->where('id', '=', (int) $row->id)
            ->update([
                'next_value' => $first + $count,
                'updated_at' => now(),
            ]);

        return $first;
    }

    /**
     * The number the next allocation would return, WITHOUT consuming it.
     *
     * For the admission wizard's read-only "(Auto)" preview
     * (docs/specs/07-students.md 6.2): a previewed number that is never
     * submitted must not be burned, and two operators previewing at the same
     * time legitimately see the same value - the second one's submit gets the
     * next. Never use this to build a number that is then inserted.
     */
    public function peek(string $series): int
    {
        $row = DB::table('sequences')->where('series', '=', $series)->first();

        return $row === null ? 1 : (int) $row->next_value;
    }

    /**
     * @return object{id: int|string, next_value: int|string}|null
     */
    private function lockRow(string $series): ?object
    {
        /** @var object{id: int|string, next_value: int|string}|null $row */
        $row = DB::table('sequences')
            ->where('series', '=', $series)
            ->lockForUpdate()
            ->first();

        return $row;
    }
}
