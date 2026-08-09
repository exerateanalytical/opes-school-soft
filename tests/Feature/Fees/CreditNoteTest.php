<?php

declare(strict_types=1);

use App\Modules\Accounting\Actions\SavePostingRule;
use App\Modules\Accounting\Domain\AccountSource;
use App\Modules\Accounting\Domain\LineSign;
use App\Modules\Accounting\Domain\PostingEvent;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Fees\Actions\AdjustInvoice;
use App\Modules\Fees\Actions\ApproveFeeAdjustment;
use App\Modules\Fees\Actions\GenerateInvoices;
use App\Modules\Fees\Actions\IssueCreditNote;
use App\Modules\Fees\Actions\IssueInvoice;
use App\Modules\Fees\Domain\CreditNoteReasonType;
use App\Modules\Fees\Domain\CreditNoteSettlementMode;
use App\Modules\Fees\Domain\CreditNoteStatus;
use App\Modules\Fees\Domain\FeeAdjustmentReasonType;
use App\Modules\Fees\Domain\FeeAdjustmentStatus;
use App\Modules\Fees\Models\CreditNote;
use App\Modules\Fees\Models\FeeAdjustment;
use App\Modules\Fees\Models\Invoice;
use App\Modules\Fees\Models\InvoiceLine;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Students\Models\Enrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

require_once __DIR__.'/../Accounting/AccountingTestHelpers.php';

uses(RefreshDatabase::class);

if (! function_exists('feesAccountId')) {
    function feesAccountId(string $code): int
    {
        return (int) assertNotNull(
            DB::table('chart_of_accounts')->where('code', $code)->value('id'),
            "Account {$code} is not seeded.",
        );
    }
}

if (! function_exists('feesOwnItemId')) {
    function feesOwnItemId(string $name, string $revenueCode = '706'): int
    {
        $categoryId = DB::table('fee_categories')->insertGetId([
            'code' => 'CAT'.Str::upper(Str::random(6)),
            'name' => $name.' category',
            'name_fr' => $name.' catégorie',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('fee_items')->insertGetId([
            'code' => 'ITM'.Str::upper(Str::random(6)),
            'name' => $name,
            'name_fr' => $name.' (fr)',
            'fee_category_id' => $categoryId,
            'collection_basis' => 'own_revenue',
            'revenue_account_id' => feesAccountId($revenueCode),
            'recognition_method' => 'on_issue',
            'default_recurrence' => 'per_year',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

if (! function_exists('feesStructure')) {
    /**
     * Inserts an active fee structure with the given scope and item lines.
     *
     * @param  list<array{fee_item_id: int, amount: int}>  $lines
     * @param  array<string, mixed>  $scope
     */
    function feesStructure(int $academicYearId, int $schoolSectionId, array $lines, array $scope = []): int
    {
        $structureId = (int) DB::table('fee_structures')->insertGetId(array_merge([
            'academic_year_id' => $academicYearId,
            'school_section_id' => $schoolSectionId,
            'name' => 'Structure '.Str::upper(Str::random(5)),
            'status' => 'active',
            'effective_from' => '2030-09-01',
            'created_at' => now(),
            'updated_at' => now(),
        ], $scope));

        foreach ($lines as $order => $line) {
            DB::table('fee_structure_lines')->insert([
                'fee_structure_id' => $structureId,
                'fee_item_id' => $line['fee_item_id'],
                'amount' => $line['amount'],
                'display_order' => $order + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $structureId;
    }
}

if (! function_exists('feesFixture')) {
    /**
     * The §15.1-shaped baseline: an enrollment plus an active structure of
     * two own-revenue lines totalling 350 000, sharing the ledger calendar
     * that covers the 2031-03-15 issue date.
     *
     * @return array{enrollment: Enrollment, structure_id: int, options: array{academic_year_id: int, fiscal_year_id: int, term_id: int|null, issue_date: string, due_date: string, installment_plan_id?: int|null}}
     */
    function feesFixture(): array
    {
        $calendar = ledgerCalendar('2031-03-15');

        /** @var Enrollment $enrollment */
        $enrollment = Enrollment::factory()->create([
            'academic_year_id' => $calendar['academic_year_id'],
        ]);

        $structureId = feesStructure(
            $enrollment->academic_year_id,
            $enrollment->school_section_id,
            [
                ['fee_item_id' => feesOwnItemId('Tuition Fee'), 'amount' => 200_000],
                ['fee_item_id' => feesOwnItemId('Development Fee'), 'amount' => 150_000],
            ],
        );

        return [
            'enrollment' => $enrollment,
            'structure_id' => $structureId,
            'options' => [
                'academic_year_id' => $enrollment->academic_year_id,
                'fiscal_year_id' => $calendar['fiscal_year_id'],
                'term_id' => null,
                'issue_date' => '2031-03-15',
                'due_date' => '2031-06-30',
            ],
        ];
    }
}

if (! function_exists('feesIssueUserAs')) {
    /** A logged-in user holding the union of the given roles' permissions. */
    function feesIssueUserAs(Role ...$roles): User
    {
        (new Database\Seeders\RolePermissionSeeder())->run();
        $user = User::factory()->create();

        foreach ($roles as $role) {
            $user->assignRole($role->value);
        }

        $user = $user->fresh() ?? $user;
        actingAs($user);

        return $user;
    }
}

if (! function_exists('feesSaveInvoiceIssuedRule')) {
    /**
     * The §11.2 `fee.invoice.issued` rule, saved through the real
     * SavePostingRule gate: Dr the payload's receivable (4111 gross, student
     * partner), Cr one line per invoice line into its snapshotted revenue
     * account - iteration under the 'item.' prefix.
     */
    function feesSaveInvoiceIssuedRule(User $accountant): void
    {
        app(SavePostingRule::class)->handle([
            'code' => 'f2_invoice_issued',
            'event' => PostingEvent::FeeInvoiceIssued->value,
            'journal_id' => Journal::factory()->create()->id,
            'label_expression' => 'Facture {invoice.reference}',
            'condition_expression' => null,
            'priority' => 100,
            'is_active' => true,
            'effective_from' => '2030-01-01',
            'effective_to' => null,
        ], [
            [
                'sequence' => 1,
                'account_source' => AccountSource::PayloadPath,
                'account_path' => 'invoice.receivable_account_id',
                'sign' => LineSign::Debit,
                'amount_expression' => 'invoice.total',
                'partner_source' => 'invoice.partner',
                'label_expression' => 'Client — {invoice.reference}',
            ],
            [
                'sequence' => 2,
                'account_source' => AccountSource::PayloadPath,
                'account_path' => 'item.revenue_account_id',
                'iterates_over' => 'invoice.lines',
                'sign' => LineSign::Credit,
                'amount_expression' => 'item.amount',
                'label_expression' => '{item.label}',
            ],
        ], $accountant->toAuditActor());
    }
}

if (! function_exists('feesSaveCreditNoteRule')) {
    /**
     * The `fee.credit_note.issued` mirror rule: Dr each credited line's
     * contra source, Cr the receivable with the student partner.
     */
    function feesSaveCreditNoteRule(User $accountant): void
    {
        app(SavePostingRule::class)->handle([
            'code' => 'f2_credit_note_issued',
            'event' => PostingEvent::FeeCreditNoteIssued->value,
            'journal_id' => Journal::factory()->create()->id,
            'label_expression' => 'Avoir {invoice.reference}',
            'condition_expression' => null,
            'priority' => 100,
            'is_active' => true,
            'effective_from' => '2030-01-01',
            'effective_to' => null,
        ], [
            [
                'sequence' => 1,
                'account_source' => AccountSource::PayloadPath,
                'account_path' => 'item.revenue_account_id',
                'iterates_over' => 'invoice.lines',
                'sign' => LineSign::Debit,
                'amount_expression' => 'item.amount',
                'label_expression' => '{item.label}',
            ],
            [
                'sequence' => 2,
                'account_source' => AccountSource::PayloadPath,
                'account_path' => 'invoice.receivable_account_id',
                'sign' => LineSign::Credit,
                'amount_expression' => 'invoice.total',
                'partner_source' => 'invoice.partner',
                'label_expression' => 'Client — {invoice.reference}',
            ],
        ], $accountant->toAuditActor());
    }
}

if (! function_exists('feesSaveAdjustmentRule')) {
    /**
     * The `fee.adjustment.granted` rule: Dr the reason-resolved counterpart
     * (contra-revenue for a discount), Cr the receivable with the partner.
     */
    function feesSaveAdjustmentRule(User $accountant): void
    {
        app(SavePostingRule::class)->handle([
            'code' => 'f2_adjustment_granted',
            'event' => PostingEvent::FeeAdjustmentGranted->value,
            'journal_id' => Journal::factory()->create()->id,
            'label_expression' => 'Ajustement {adjustment.reference}',
            'condition_expression' => null,
            'priority' => 100,
            'is_active' => true,
            'effective_from' => '2030-01-01',
            'effective_to' => null,
        ], [
            [
                'sequence' => 1,
                'account_source' => AccountSource::PayloadPath,
                'account_path' => 'adjustment.counterpart_account_id',
                'sign' => LineSign::Debit,
                'amount_expression' => 'adjustment.amount',
                'label_expression' => 'Remise {adjustment.reference}',
            ],
            [
                'sequence' => 2,
                'account_source' => AccountSource::PayloadPath,
                'account_path' => 'adjustment.receivable_account_id',
                'sign' => LineSign::Credit,
                'amount_expression' => 'adjustment.amount',
                'partner_source' => 'adjustment.partner',
                'label_expression' => 'Client — {adjustment.reference}',
            ],
        ], $accountant->toAuditActor());
    }
}

if (! function_exists('feesIssuedInvoiceFixture')) {
    /**
     * An ISSUED invoice built entirely through the real Actions:
     * GenerateInvoices → IssueInvoice, over the §15.1 two-line structure
     * (Tuition 200 000, Development 150 000).
     *
     * @return array{invoice: Invoice, lines: list<InvoiceLine>, user: User}
     */
    function feesIssuedInvoiceFixture(User $user): array
    {
        feesSaveInvoiceIssuedRule($user);
        $fixture = feesFixture();

        $result = app(GenerateInvoices::class)->forEnrollments(
            [$fixture['enrollment']->id],
            $fixture['options'],
            $user->toAuditActor(),
        );

        [$invoice] = app(IssueInvoice::class)->handle($result['created'], $user->toAuditActor());

        /** @var list<InvoiceLine> $lines */
        $lines = $invoice->lines()->get()->all();

        return ['invoice' => $invoice, 'lines' => $lines, 'user' => $user];
    }
}

it('issues a line-level credit note against an issued invoice and posts through PostFromEvent', function (): void {
    $user = feesIssueUserAs(Role::Bursar, Role::Accountant);
    feesSaveCreditNoteRule($user);
    $set = feesIssuedInvoiceFixture($user);

    $creditNote = app(IssueCreditNote::class)->handle(
        $set['invoice']->id,
        [['invoice_line_id' => $set['lines'][0]->id, 'amount' => 50_000]],
        CreditNoteReasonType::OverInvoiced,
        'Charged for a term the student did not attend.',
        CreditNoteSettlementMode::ApplyToAccount,
        '2031-03-16',
        $user->toAuditActor(),
    );

    expect($creditNote->credit_note_no)->toMatch('/^AV\/2031\/\d{6}$/')
        ->and($creditNote->status)->toBe(CreditNoteStatus::Issued)
        ->and($creditNote->journal_entry_id)->not->toBeNull();

    /** @var JournalEntry $entry */
    $entry = JournalEntry::query()->findOrFail($creditNote->journal_entry_id);
    expect($entry->status)->toBe(JournalEntry::STATUS_POSTED)
        ->and($entry->total_debit)->toBe(50_000)
        ->and($entry->total_credit)->toBe(50_000);

    // The §5 outstanding drops by exactly the credited amount.
    expect($set['invoice']->refresh()->outstandingAsOf(Illuminate\Support\Carbon::parse('2031-03-31')))->toBe(300_000);
});

it('caps a credit note at the invoiced amount per line - you cannot credit more than remains billed', function (): void {
    $user = feesIssueUserAs(Role::Bursar, Role::Accountant);
    feesSaveCreditNoteRule($user);
    $set = feesIssuedInvoiceFixture($user);

    // Line 1 carries 200 000; crediting 250 000 must refuse, not truncate.
    app(IssueCreditNote::class)->handle(
        $set['invoice']->id,
        [['invoice_line_id' => $set['lines'][0]->id, 'amount' => 250_000]],
        CreditNoteReasonType::OverInvoiced,
        'Over-credit attempt.',
        CreditNoteSettlementMode::ApplyToAccount,
        '2031-03-16',
        $user->toAuditActor(),
    );
})->throws(DomainException::class, 'You cannot credit more than remains billed');

it('counts prior credit notes AND approved adjustments against the per-line cap', function (): void {
    $user = feesIssueUserAs(Role::Bursar, Role::Accountant);
    feesSaveCreditNoteRule($user);
    feesSaveAdjustmentRule($user);
    $set = feesIssuedInvoiceFixture($user);

    // 120 000 already credited on the 200 000 line...
    app(IssueCreditNote::class)->handle(
        $set['invoice']->id,
        [['invoice_line_id' => $set['lines'][0]->id, 'amount' => 120_000]],
        CreditNoteReasonType::PriceCorrection,
        'First correction.',
        CreditNoteSettlementMode::ApplyToAccount,
        '2031-03-16',
        $user->toAuditActor(),
    );

    // ...plus a 50 000 APPROVED discount: only 30 000 remains billed.
    $adjustment = app(AdjustInvoice::class)->handle([
        'invoice_line_id' => $set['lines'][0]->id,
        'amount' => 50_000,
        'reason_type' => FeeAdjustmentReasonType::Hardship,
        'reason_note' => 'Hardship discount.',
        'adjustment_account_id' => feesAccountId('706'),
        'effective_date' => '2031-03-17',
    ], $user->toAuditActor());
    $approver = feesIssueUserAs(Role::Bursar, Role::Accountant);
    app(ApproveFeeAdjustment::class)->handle((int) $adjustment->getKey(), $approver->toAuditActor());

    actingAs($user);
    expect(fn (): CreditNote => app(IssueCreditNote::class)->handle(
        $set['invoice']->id,
        [['invoice_line_id' => $set['lines'][0]->id, 'amount' => 30_001]],
        CreditNoteReasonType::PriceCorrection,
        'One franc too far.',
        CreditNoteSettlementMode::ApplyToAccount,
        '2031-03-18',
        $user->toAuditActor(),
    ))->toThrow(DomainException::class, 'only 30000 remains billed');

    // Exactly the remaining 30 000 still passes.
    $exact = app(IssueCreditNote::class)->handle(
        $set['invoice']->id,
        [['invoice_line_id' => $set['lines'][0]->id, 'amount' => 30_000]],
        CreditNoteReasonType::PriceCorrection,
        'The rest of the line.',
        CreditNoteSettlementMode::ApplyToAccount,
        '2031-03-19',
        $user->toAuditActor(),
    );

    expect($exact->status)->toBe(CreditNoteStatus::Issued);
});

it('is idempotent on the idempotency key - a double-clicked avoir returns the same credit note', function (): void {
    $user = feesIssueUserAs(Role::Bursar, Role::Accountant);
    feesSaveCreditNoteRule($user);
    $set = feesIssuedInvoiceFixture($user);

    $issue = fn (): CreditNote => app(IssueCreditNote::class)->handle(
        $set['invoice']->id,
        [['invoice_line_id' => $set['lines'][0]->id, 'amount' => 40_000]],
        CreditNoteReasonType::Goodwill,
        'Goodwill gesture.',
        CreditNoteSettlementMode::ApplyToAccount,
        '2031-03-16',
        $user->toAuditActor(),
        'avoir-abc-123',
    );

    $first = $issue();
    $second = $issue();

    expect($second->id)->toBe($first->id)
        ->and(CreditNote::query()->count())->toBe(1);
});

it('refuses a credit note against a draft invoice', function (): void {
    $user = feesIssueUserAs(Role::Bursar, Role::Accountant);
    feesSaveCreditNoteRule($user);
    $fixture = feesFixture();

    $result = app(GenerateInvoices::class)->forEnrollments(
        [$fixture['enrollment']->id],
        $fixture['options'],
        $user->toAuditActor(),
    );

    /** @var Invoice $draft */
    $draft = Invoice::query()->findOrFail($result['created'][0]);
    /** @var InvoiceLine $line */
    $line = $draft->lines()->firstOrFail();

    app(IssueCreditNote::class)->handle(
        $draft->id,
        [['invoice_line_id' => $line->id, 'amount' => 10_000]],
        CreditNoteReasonType::OverInvoiced,
        'Draft target.',
        CreditNoteSettlementMode::ApplyToAccount,
        '2031-03-16',
        $user->toAuditActor(),
    );
})->throws(DomainException::class, 'a credit note corrects an ISSUED invoice');

it('records a PENDING adjustment and blocks a reduction past what remains billed on the line', function (): void {
    $user = feesIssueUserAs(Role::Bursar, Role::Accountant);
    $set = feesIssuedInvoiceFixture($user);

    $adjustment = app(AdjustInvoice::class)->handle([
        'invoice_line_id' => $set['lines'][0]->id,
        'amount' => 80_000,
        'reason_type' => FeeAdjustmentReasonType::SiblingDiscount,
        'reason_note' => 'Second sibling enrolled.',
        'adjustment_account_id' => feesAccountId('706'),
        'effective_date' => '2031-03-16',
    ], $user->toAuditActor());

    expect($adjustment->reference_no)->toMatch('/^ADJ\/2031\/\d{6}$/')
        ->and($adjustment->status)->toBe(FeeAdjustmentStatus::Pending)
        ->and($adjustment->granted_by)->toBe($user->id)
        ->and($adjustment->journal_entry_id)->toBeNull(); // nothing posts before approval

    // A reduction beyond the line's 200 000 refuses outright.
    expect(fn (): FeeAdjustment => app(AdjustInvoice::class)->handle([
        'invoice_line_id' => $set['lines'][0]->id,
        'amount' => 250_000,
        'reason_type' => FeeAdjustmentReasonType::Hardship,
        'reason_note' => 'Too generous.',
        'adjustment_account_id' => feesAccountId('706'),
        'effective_date' => '2031-03-16',
    ], $user->toAuditActor()))->toThrow(DomainException::class, 'exceeds the 200000 remaining billed');
});

it('enforces maker-checker: the granter cannot approve their own adjustment; a second user can, and approval posts', function (): void {
    $granter = feesIssueUserAs(Role::Bursar, Role::Accountant);
    feesSaveAdjustmentRule($granter);
    $set = feesIssuedInvoiceFixture($granter);

    $adjustment = app(AdjustInvoice::class)->handle([
        'invoice_line_id' => $set['lines'][0]->id,
        'amount' => 60_000,
        'reason_type' => FeeAdjustmentReasonType::ScholarshipInternal,
        'reason_note' => 'Merit scholarship.',
        'adjustment_account_id' => feesAccountId('706'),
        'effective_date' => '2031-03-16',
    ], $granter->toAuditActor());

    // The granter approving their own grant is refused (§8 segregation).
    expect(fn (): FeeAdjustment => app(ApproveFeeAdjustment::class)->handle((int) $adjustment->getKey(), $granter->toAuditActor()))
        ->toThrow(DomainException::class, 'Segregation of duties');

    $approver = feesIssueUserAs(Role::Bursar, Role::Accountant);
    $approved = app(ApproveFeeAdjustment::class)->handle((int) $adjustment->getKey(), $approver->toAuditActor());

    expect($approved->status)->toBe(FeeAdjustmentStatus::Approved)
        ->and($approved->approved_by)->toBe($approver->id)
        ->and($approved->journal_entry_id)->not->toBeNull();

    /** @var JournalEntry $entry */
    $entry = JournalEntry::query()->findOrFail($approved->journal_entry_id);
    expect($entry->status)->toBe(JournalEntry::STATUS_POSTED)
        ->and($entry->total_debit)->toBe(60_000)
        ->and($entry->total_credit)->toBe(60_000);

    // A second approval of the same adjustment refuses - it is no longer pending.
    expect(fn (): FeeAdjustment => app(ApproveFeeAdjustment::class)->handle((int) $adjustment->getKey(), $approver->toAuditActor()))
        ->toThrow(DomainException::class, 'only a pending adjustment can be approved');
});

it('refuses an adjustment against a draft invoice - a draft is simply edited', function (): void {
    $user = feesIssueUserAs(Role::Bursar, Role::Accountant);
    $fixture = feesFixture();

    $result = app(GenerateInvoices::class)->forEnrollments(
        [$fixture['enrollment']->id],
        $fixture['options'],
        $user->toAuditActor(),
    );

    /** @var Invoice $draft */
    $draft = Invoice::query()->findOrFail($result['created'][0]);
    /** @var InvoiceLine $line */
    $line = $draft->lines()->firstOrFail();

    app(AdjustInvoice::class)->handle([
        'invoice_line_id' => $line->id,
        'amount' => 10_000,
        'reason_type' => FeeAdjustmentReasonType::Hardship,
        'reason_note' => 'Draft target.',
        'adjustment_account_id' => feesAccountId('706'),
        'effective_date' => '2031-03-16',
    ], $user->toAuditActor());
})->throws(DomainException::class, 'adjustments target ISSUED invoices');
