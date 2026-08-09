<?php

declare(strict_types=1);

use App\Modules\Accounting\Actions\SavePostingRule;
use App\Modules\Accounting\Domain\AccountSource;
use App\Modules\Accounting\Domain\LineSign;
use App\Modules\Accounting\Domain\PostingEvent;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Fees\Actions\GenerateInvoices;
use App\Modules\Fees\Actions\IssueInvoice;
use App\Modules\Fees\Domain\InvoiceStatus;
use App\Modules\Fees\Models\Invoice;
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

if (! function_exists('feesDraftInvoice')) {
    /**
     * One DRAFT invoice generated through the real GenerateInvoices path.
     *
     * @param  array{enrollment: Enrollment, structure_id: int, options: array{academic_year_id: int, fiscal_year_id: int, term_id: int|null, issue_date: string, due_date: string, installment_plan_id?: int|null}}  $fixture
     */
    function feesDraftInvoice(array $fixture, User $user): Invoice
    {
        $result = app(GenerateInvoices::class)->forEnrollments(
            [$fixture['enrollment']->id],
            $fixture['options'],
            $user->toAuditActor(),
        );

        /** @var Invoice $invoice */
        $invoice = Invoice::query()->findOrFail($result['created'][0]);

        return $invoice;
    }
}

it('issues a draft: allocates INV/{YYYY}/{seq} and posts Dr 4111 / Cr revenue through PostFromEvent', function (): void {
    $user = feesIssueUserAs(Role::Bursar, Role::Accountant);
    feesSaveInvoiceIssuedRule($user);
    $fixture = feesFixture();
    $draft = feesDraftInvoice($fixture, $user);

    [$invoice] = app(IssueInvoice::class)->handle([$draft->id], $user->toAuditActor());

    expect($invoice->status)->toBe(InvoiceStatus::Issued)
        ->and($invoice->invoice_no)->toMatch('/^INV\/2031\/\d{6}$/')
        ->and($invoice->version)->toBe(1)
        ->and($invoice->journal_entry_id)->not->toBeNull();

    /** @var JournalEntry $entry */
    $entry = JournalEntry::query()->findOrFail($invoice->journal_entry_id);
    expect($entry->status)->toBe(JournalEntry::STATUS_POSTED)
        ->and($entry->total_debit)->toBe(350_000)
        ->and($entry->total_credit)->toBe(350_000);

    $lines = $entry->lines()->orderBy('sequence')->get()->all();
    expect($lines)->toHaveCount(3);

    /** @var JournalEntryLine $client */
    $client = $lines[0];
    // Dr 4111 gross, carrying the student partner for the aux ledger.
    expect($client->account_id)->toBe(feesAccountId('4111'))
        ->and($client->debit)->toBe(350_000)
        ->and($client->partner_type?->value)->toBe('student')
        ->and($client->partner_id)->toBe($invoice->student_id)
        // Cr one line per invoice line, into the snapshotted revenue account.
        ->and($lines[1]->credit)->toBe(200_000)
        ->and($lines[1]->account_id)->toBe(feesAccountId('706'))
        ->and($lines[2]->credit)->toBe(150_000)
        ->and($lines[2]->account_id)->toBe(feesAccountId('706'));
});

it('numbers invoices sequentially through the SequenceAllocator inside the issuing transaction', function (): void {
    $user = feesIssueUserAs(Role::Bursar, Role::Accountant);
    feesSaveInvoiceIssuedRule($user);
    $fixture = feesFixture();

    // A second enrollment in the same section: the sentinel-scoped fixture
    // structure covers it too.
    /** @var Enrollment $second */
    $second = Enrollment::factory()->create([
        'academic_year_id' => $fixture['enrollment']->academic_year_id,
        'school_section_id' => $fixture['enrollment']->school_section_id,
    ]);

    $result = app(GenerateInvoices::class)->forEnrollments(
        [$fixture['enrollment']->id, $second->id],
        $fixture['options'],
        $user->toAuditActor(),
    );

    $issued = app(IssueInvoice::class)->handle($result['created'], $user->toAuditActor());

    expect($issued)->toHaveCount(2);

    $first = (int) substr((string) $issued[0]->invoice_no, -6);
    $next = (int) substr((string) $issued[1]->invoice_no, -6);

    expect($next)->toBe($first + 1); // gaps permitted, order guaranteed
});

it('is idempotent: re-issuing an issued invoice returns it unchanged and posts nothing twice', function (): void {
    $user = feesIssueUserAs(Role::Bursar, Role::Accountant);
    feesSaveInvoiceIssuedRule($user);
    $fixture = feesFixture();
    $draft = feesDraftInvoice($fixture, $user);

    [$first] = app(IssueInvoice::class)->handle([$draft->id], $user->toAuditActor());
    [$second] = app(IssueInvoice::class)->handle([$draft->id], $user->toAuditActor());

    expect($second->invoice_no)->toBe($first->invoice_no)
        ->and($second->journal_entry_id)->toBe($first->journal_entry_id)
        ->and($second->version)->toBe($first->version)
        ->and(JournalEntry::query()->count())->toBe(1);
});

it('refuses to issue a cancelled invoice - only a draft can be issued', function (): void {
    $user = feesIssueUserAs(Role::Bursar, Role::Accountant);
    feesSaveInvoiceIssuedRule($user);
    $fixture = feesFixture();
    $draft = feesDraftInvoice($fixture, $user);

    DB::table('invoices')->where('id', $draft->id)->update([
        'status' => 'cancelled',
        'cancelled_at' => now(),
    ]);

    app(IssueInvoice::class)->handle([$draft->id], $user->toAuditActor());
})->throws(DomainException::class, 'only a draft can be issued');

it('FAILS LOUDLY without a posting rule and the invoice REMAINS DRAFT - no rule ships seeded (00-core §16)', function (): void {
    $user = feesIssueUserAs(Role::Bursar, Role::Accountant);
    $fixture = feesFixture();
    $draft = feesDraftInvoice($fixture, $user);

    expect(fn (): array => app(IssueInvoice::class)->handle([$draft->id], $user->toAuditActor()))
        ->toThrow(DomainException::class, "No active posting rule matches event 'fee.invoice.issued'");

    // The whole issuing transaction rolled back: still draft, no number,
    // no journal entry - an invoice may not claim `issued` without one.
    $draft->refresh();
    expect($draft->status)->toBe(InvoiceStatus::Draft)
        ->and($draft->invoice_no)->toBeNull()
        ->and($draft->journal_entry_id)->toBeNull()
        ->and(JournalEntry::query()->count())->toBe(0);
});

it('denies issuing to a user without fee.collect', function (): void {
    $user = feesIssueUserAs(Role::Bursar, Role::Accountant);
    feesSaveInvoiceIssuedRule($user);
    $fixture = feesFixture();
    $draft = feesDraftInvoice($fixture, $user);

    $teacher = feesIssueUserAs(Role::Teacher);

    app(IssueInvoice::class)->handle([$draft->id], $teacher->toAuditActor());
})->throws(Illuminate\Auth\Access\AuthorizationException::class);
