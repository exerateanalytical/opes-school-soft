<?php

declare(strict_types=1);

use App\Modules\Academics\Models\SchoolSection;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Fees\Actions\CarryForwardStudentCredit;
use App\Modules\Operations\Actions\Rollover\CarryBalancesStep;
use App\Modules\Operations\Domain\RolloverStep;
use App\Modules\Operations\Models\RolloverArtifact;
use App\Modules\Operations\Models\RolloverBalanceCarry;
use App\Modules\Students\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/P7F3RolloverTestHelpers.php';

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// §6.2 step 7 - credit carries: one posting PER STUDENT, single posting path
// ---------------------------------------------------------------------------

it('carries each student credit as its own per-student posting - never netted across students', function () {
    $operator = p7f3Operator();
    $years = p7f3Years();
    $calendar = p7f3Calendar();
    p7f3SavePaymentRule($operator);
    p7f3SaveCarryRule($operator);

    $alice = Student::factory()->create();
    $bob = Student::factory()->create();

    // Two families paid at registration, before any invoice existed (C10):
    // the whole gross of each payment is that student's unallocated credit.
    p7f3PayCredit($alice, $years['from'], $calendar['fiscal_year_id'], 80_000, $operator);
    p7f3PayCredit($bob, $years['from'], $calendar['fiscal_year_id'], 50_000, $operator);

    $entriesBefore = JournalEntry::query()->count();

    $run = p7f3RunAt($years['from'], $years['to'], RolloverStep::CarryBalances->value, $operator);
    $summary = app(CarryBalancesStep::class)->handle($run->id, [], $operator->toAuditActor());

    expect($summary['credits_carried'])->toBe(2)
        ->and($summary['credit_total'])->toBe(130_000)
        ->and($summary['debts_carried'])->toBe(0);

    $carries = RolloverBalanceCarry::query()
        ->where('rollover_run_id', $run->id)
        ->orderBy('student_id')
        ->get();

    expect($carries)->toHaveCount(2)
        ->and($carries->pluck('kind')->unique()->all())->toBe([RolloverBalanceCarry::KIND_CREDIT_CARRY]);

    // Exactly TWO new entries - one per student. A single 130 000 entry
    // would be the OHADA netting 04-fees C9/A5 forbids.
    expect(JournalEntry::query()->count())->toBe($entriesBefore + 2);

    $byStudent = [
        $alice->id => 80_000,
        $bob->id => 50_000,
    ];

    foreach ($carries as $carry) {
        $expected = $byStudent[$carry->student_id];

        expect($carry->amount)->toBe($expected)
            ->and($carry->journal_entry_id)->not->toBeNull();

        /** @var JournalEntry $entry */
        $entry = JournalEntry::query()->findOrFail($carry->journal_entry_id);

        // SINGLE POSTING PATH: the entry exists because PostFromEvent
        // selected and evaluated a posting rule - provenance stamped, posted
        // status, never a hand-built entry from the Operations module.
        expect($entry->status)->toBe(JournalEntry::STATUS_POSTED)
            ->and($entry->source_type)->toBe('posting_event')
            ->and($entry->posting_rule_id)->not->toBeNull()
            ->and($entry->total_debit)->toBe($expected)
            ->and($entry->total_credit)->toBe($expected);

        // §15.9 shape: Dr 4111 / Cr 4191, BOTH lines partnered to THIS
        // student and no one else.
        $lines = $entry->lines()->orderBy('sequence')->get();
        expect($lines)->toHaveCount(2);

        $code = function (JournalEntryLine $line): string {
            /** @var ChartOfAccount $account */
            $account = ChartOfAccount::query()->findOrFail($line->account_id);

            return $account->code;
        };

        /** @var JournalEntryLine $receivable */
        $receivable = $lines[0];
        /** @var JournalEntryLine $advance */
        $advance = $lines[1];

        expect($code($receivable))->toBe('4111')
            ->and($receivable->debit)->toBe($expected)
            ->and($receivable->partner_id)->toBe($carry->student_id)
            ->and($code($advance))->toBe('4191')
            ->and($advance->credit)->toBe($expected)
            ->and($advance->partner_id)->toBe($carry->student_id);
    }

    // Undo ledger + resumability bookkeeping.
    expect(RolloverArtifact::query()
        ->where('rollover_run_id', $run->id)
        ->where('entity_type', 'rollover_balance_carries')
        ->count())->toBe(2)
        ->and($run->fresh()?->current_step)->toBe(RolloverStep::ArchiveLeavers->value);
});

it('does not net within a student either: a credit carries while the debt still needs its own explicit choice', function () {
    $operator = p7f3Operator();
    $years = p7f3Years();
    $calendar = p7f3Calendar();
    p7f3SavePaymentRule($operator);
    p7f3SaveCarryRule($operator);

    $section = SchoolSection::factory()->create();
    $level = p7f3Level($section);
    $group = p7f3Group($years['from'], $level, 'Form 1 A');

    $student = Student::factory()->create();
    $enrollment = p7f3Enroll($student, $years['from'], $group, '2030-09-05');

    // Credit recorded FIRST (nothing to allocate against), invoice issued
    // AFTER - so the student holds 30 000 credit AND owes 20 000.
    p7f3PayCredit($student, $years['from'], $calendar['fiscal_year_id'], 30_000, $operator);
    p7f3IssueInvoice($student, $enrollment, $years['from'], $calendar['fiscal_year_id'], 20_000, $operator);

    $run = p7f3RunAt($years['from'], $years['to'], RolloverStep::CarryBalances->value, $operator);
    $step = app(CarryBalancesStep::class);

    // The debt is NOT silently offset against the credit: it needs a choice.
    expect(fn () => $step->handle($run->id, [], $operator->toAuditActor()))
        ->toThrow(DomainException::class, "student {$student->id} (20000)");

    $summary = $step->handle($run->id, [$student->id => CarryBalancesStep::CHOICE_DEBT_CARRY], $operator->toAuditActor());

    expect($summary['credits_carried'])->toBe(1)
        ->and($summary['debts_carried'])->toBe(1);

    $carries = RolloverBalanceCarry::query()->where('rollover_run_id', $run->id)->get();

    // Two rows for one student, kinds kept apart - the FULL 30 000 credit
    // carried, the FULL 20 000 debt recorded, never a 10 000 net.
    expect($carries)->toHaveCount(2)
        ->and($carries->firstWhere('kind', RolloverBalanceCarry::KIND_CREDIT_CARRY)?->amount)->toBe(30_000)
        ->and($carries->firstWhere('kind', RolloverBalanceCarry::KIND_DEBT_CARRY)?->amount)->toBe(20_000)
        ->and($carries->firstWhere('kind', RolloverBalanceCarry::KIND_DEBT_CARRY)?->journal_entry_id)->toBeNull();
});

it('refuses when a student still owing has no per-student choice, naming them - the "students still owing" list', function () {
    $operator = p7f3Operator();
    $years = p7f3Years();
    $calendar = p7f3Calendar();
    p7f3SaveCarryRule($operator);

    $section = SchoolSection::factory()->create();
    $level = p7f3Level($section);
    $group = p7f3Group($years['from'], $level, 'Form 1 A');

    $debtor = Student::factory()->create();
    $enrollment = p7f3Enroll($debtor, $years['from'], $group, '2030-09-05');
    p7f3IssueInvoice($debtor, $enrollment, $years['from'], $calendar['fiscal_year_id'], 100_000, $operator);

    $run = p7f3RunAt($years['from'], $years['to'], RolloverStep::CarryBalances->value, $operator);

    expect(fn () => app(CarryBalancesStep::class)->handle($run->id, [], $operator->toAuditActor()))
        ->toThrow(DomainException::class, "student {$debtor->id} (100000)");

    // Nothing was recorded and the step did not advance.
    expect(RolloverBalanceCarry::query()->count())->toBe(0)
        ->and($run->fresh()?->current_step)->toBe(RolloverStep::CarryBalances->value);
});

it('records debt_carry and block choices without touching the ledger - the debt remains on the old year invoices', function () {
    $operator = p7f3Operator();
    $years = p7f3Years();
    $calendar = p7f3Calendar();
    p7f3SaveCarryRule($operator);

    $section = SchoolSection::factory()->create();
    $level = p7f3Level($section);
    $group = p7f3Group($years['from'], $level, 'Form 1 A');

    $carried = Student::factory()->create();
    $blocked = Student::factory()->create();
    $carriedEnrollment = p7f3Enroll($carried, $years['from'], $group, '2030-09-05');
    $blockedEnrollment = p7f3Enroll($blocked, $years['from'], $group, '2030-09-05');

    $carriedInvoice = p7f3IssueInvoice($carried, $carriedEnrollment, $years['from'], $calendar['fiscal_year_id'], 100_000, $operator);
    p7f3IssueInvoice($blocked, $blockedEnrollment, $years['from'], $calendar['fiscal_year_id'], 40_000, $operator);

    $entriesBefore = JournalEntry::query()->count();

    $run = p7f3RunAt($years['from'], $years['to'], RolloverStep::CarryBalances->value, $operator);
    $summary = app(CarryBalancesStep::class)->handle($run->id, [
        $carried->id => CarryBalancesStep::CHOICE_DEBT_CARRY,
        $blocked->id => CarryBalancesStep::CHOICE_BLOCK,
    ], $operator->toAuditActor());

    expect($summary['debts_carried'])->toBe(1)
        ->and($summary['blocked'])->toBe(1)
        ->and($summary['credits_carried'])->toBe(0)
        // No posting for a debt choice: the invoice IS the receivable already.
        ->and(JournalEntry::query()->count())->toBe($entriesBefore);

    $rows = RolloverBalanceCarry::query()->where('rollover_run_id', $run->id)->orderBy('student_id')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->firstWhere('student_id', $carried->id)?->kind)->toBe(RolloverBalanceCarry::KIND_DEBT_CARRY)
        ->and($rows->firstWhere('student_id', $carried->id)?->amount)->toBe(100_000)
        ->and($rows->firstWhere('student_id', $blocked->id)?->kind)->toBe(RolloverBalanceCarry::KIND_BLOCK)
        ->and($rows->pluck('journal_entry_id')->filter()->all())->toBe([]);

    // The old-year invoice is untouched - the debt "remains on the old
    // year's invoices" (§6.2 step 7, verbatim).
    expect((string) DB::table('invoices')->where('id', $carriedInvoice['invoice_id'])->value('status'))->toBe('issued');
});

it('refuses a write-off from the wizard - that is a maker-checker Fees workflow, not a rollover click', function () {
    $operator = p7f3Operator();
    $years = p7f3Years();
    $calendar = p7f3Calendar();

    $section = SchoolSection::factory()->create();
    $level = p7f3Level($section);
    $group = p7f3Group($years['from'], $level, 'Form 1 A');

    $debtor = Student::factory()->create();
    $enrollment = p7f3Enroll($debtor, $years['from'], $group, '2030-09-05');
    p7f3IssueInvoice($debtor, $enrollment, $years['from'], $calendar['fiscal_year_id'], 60_000, $operator);

    $run = p7f3RunAt($years['from'], $years['to'], RolloverStep::CarryBalances->value, $operator);

    expect(fn () => app(CarryBalancesStep::class)->handle($run->id, [
        $debtor->id => 'write_off',
    ], $operator->toAuditActor()))
        ->toThrow(DomainException::class, 'maker-checker');

    expect(RolloverBalanceCarry::query()->count())->toBe(0);
});

it('is idempotent on resume: a second execution of step 7 posts nothing twice (§6.3)', function () {
    $operator = p7f3Operator();
    $years = p7f3Years();
    $calendar = p7f3Calendar();
    p7f3SavePaymentRule($operator);
    p7f3SaveCarryRule($operator);

    $student = Student::factory()->create();
    p7f3PayCredit($student, $years['from'], $calendar['fiscal_year_id'], 80_000, $operator);

    $run = p7f3RunAt($years['from'], $years['to'], RolloverStep::CarryBalances->value, $operator);
    $step = app(CarryBalancesStep::class);

    $step->handle($run->id, [], $operator->toAuditActor());
    $entriesAfterFirst = JournalEntry::query()->count();

    // Simulate the §6.3 power cut: the process died before the step pointer
    // moved, and the operator resumes.
    DB::table('rollover_runs')->where('id', $run->id)
        ->update(['current_step' => RolloverStep::CarryBalances->value]);

    $summary = $step->handle($run->id, [], $operator->toAuditActor());

    expect($summary['skipped'])->toBe(1)
        ->and($summary['credits_carried'])->toBe(0)
        ->and(JournalEntry::query()->count())->toBe($entriesAfterFirst)
        ->and(RolloverBalanceCarry::query()->where('rollover_run_id', $run->id)->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Fees\Actions\CarryForwardStudentCredit - the door itself
// ---------------------------------------------------------------------------

it('the Fees door refuses a zero-credit carry and a same-year carry', function () {
    $operator = p7f3Operator();
    $years = p7f3Years();
    p7f3Calendar();
    p7f3SaveCarryRule($operator);

    $student = Student::factory()->create();
    $door = app(CarryForwardStudentCredit::class);

    expect(fn () => $door->handle($student->id, $years['from'], $years['to'], '2031-09-01', $operator->toAuditActor()))
        ->toThrow(DomainException::class, 'no unallocated credit')
        ->and(fn () => $door->handle($student->id, $years['from'], $years['from'], '2031-09-01', $operator->toAuditActor()))
        ->toThrow(DomainException::class, 'year it already belongs to');
});

it('a bounced payment contributes nothing to the carried credit (§5 exclusions)', function () {
    $operator = p7f3Operator();
    $years = p7f3Years();
    $calendar = p7f3Calendar();
    p7f3SavePaymentRule($operator);
    p7f3SaveCarryRule($operator);

    $student = Student::factory()->create();
    $good = p7f3PayCredit($student, $years['from'], $calendar['fiscal_year_id'], 25_000, $operator);
    $bounced = p7f3PayCredit($student, $years['from'], $calendar['fiscal_year_id'], 75_000, $operator);

    DB::table('payments')->where('id', $bounced->id)->update([
        'clearing_state' => 'bounced',
        'bounced_on' => '2031-03-20',
        'bounce_reason' => 'Provision insuffisante',
    ]);

    $posted = app(CarryForwardStudentCredit::class)->handle(
        $student->id, $years['from'], $years['to'], '2031-09-01', $operator->toAuditActor(),
    );

    expect($posted['amount'])->toBe($good->amount);
});
