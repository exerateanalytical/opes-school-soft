<?php

declare(strict_types=1);

use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Identity\Domain\Role;
use App\Modules\Students\Models\Enrollment;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\get;

require_once __DIR__.'/AccountingTestHelpers.php';

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

it('counts draft journal entries as unposted', function (): void {
    $user = ledgerUser(Role::Accountant);
    $calendar = ledgerCalendar();

    $journal = Journal::factory()->create();

    JournalEntry::query()->create([
        'journal_id' => $journal->id,
        'date' => '2031-03-15',
        'value_date' => '2031-03-15',
        'accounting_period_id' => $calendar['accounting_period_id'],
        'fiscal_year_id' => $calendar['fiscal_year_id'],
        'academic_year_id' => $calendar['academic_year_id'],
        'label' => 'Unfinished entry',
        'status' => JournalEntry::STATUS_DRAFT,
        'total_debit' => 0,
        'total_credit' => 0,
    ]);

    $this->actingAs($user);
    $response = get('/accounting/dashboard')->assertOk();

    $response->assertSeeText('1');
});

it('shows all caught up when there are no draft entries', function (): void {
    $user = ledgerUser(Role::Accountant);

    $this->actingAs($user);
    $response = get('/accounting/dashboard')->assertOk();

    $response->assertSeeText(__('opes.accounting.dashboard.all_caught_up'));
});

it('shows the books balanced tile in green on a reconciled ledger', function (): void {
    $user = ledgerUser(Role::Accountant);
    $calendar = ledgerCalendar();

    $collective = ChartOfAccount::factory()->create([
        'is_collective' => true,
        'requires_partner' => true,
        'allowed_partner_types' => ['student'],
        'is_lettrable' => true,
    ]);
    $bank = ChartOfAccount::factory()->create();

    $journal = Journal::factory()->create();

    $entry = JournalEntry::query()->create([
        'journal_id' => $journal->id,
        'piece_no' => null,
        'date' => '2031-03-15',
        'value_date' => '2031-03-15',
        'accounting_period_id' => $calendar['accounting_period_id'],
        'fiscal_year_id' => $calendar['fiscal_year_id'],
        'academic_year_id' => $calendar['academic_year_id'],
        'label' => 'Reconciled aux entry',
        'status' => JournalEntry::STATUS_DRAFT,
        'total_debit' => 0,
        'total_credit' => 0,
    ]);

    JournalEntryLine::query()->create([
        'journal_entry_id' => $entry->id,
        'sequence' => 1,
        'account_id' => $collective->id,
        'label' => 'Line 1',
        'debit' => 50000,
        'credit' => 0,
        'partner_type' => 'student',
        'partner_id' => 7,
    ]);

    JournalEntryLine::query()->create([
        'journal_entry_id' => $entry->id,
        'sequence' => 2,
        'account_id' => $bank->id,
        'label' => 'Line 2',
        'debit' => 0,
        'credit' => 50000,
    ]);

    $entry->forceFill([
        'status' => JournalEntry::STATUS_POSTED,
        'piece_no' => 'AC/2031/000001',
        'total_debit' => 50000,
        'total_credit' => 50000,
    ])->save();

    $this->actingAs($user);
    $response = get('/accounting/dashboard')->assertOk();

    $response->assertSeeText(__('opes.accounting.dashboard.balanced'));
});

it('lists a fiscal year that is currently closing', function (): void {
    $user = ledgerUser(Role::Accountant);

    \App\Modules\Accounting\Models\FiscalYear::query()->create([
        'code' => 'FY2099',
        'starts_on' => '2099-01-01',
        'ends_on' => '2099-12-31',
        'status' => 'closing',
        'is_first_exercice' => false,
    ]);

    $this->actingAs($user);
    $response = get('/accounting/dashboard')->assertOk();

    $response->assertSeeText('FY2099');
    $response->assertSee('href="'.route('accounting.year-end').'"', escape: false);
});

it('shows nothing needing attention when there is no closing year and no drafts', function (): void {
    $user = ledgerUser(Role::Accountant);

    $this->actingAs($user);
    $response = get('/accounting/dashboard')->assertOk();

    $response->assertSeeText(__('opes.accounting.dashboard.nothing_pending'));
});

it('shows the aged-receivables total and a top debtor by name', function (): void {
    $user = ledgerUser(Role::Accountant);

    $enrollment = Enrollment::factory()->create();

    DB::table('students')->where('id', $enrollment->student_id)->update([
        'first_name' => 'Aged',
        'last_name' => 'Debtor',
    ]);

    $fiscalYearId = (int) FiscalYear::factory()->open()->create()->getKey();

    $invoiceId = DB::table('invoices')->insertGetId([
        'invoice_no' => 'INV/2026/AGEDTEST',
        'enrollment_id' => $enrollment->id,
        'student_id' => $enrollment->student_id,
        'academic_year_id' => $enrollment->academic_year_id,
        'fiscal_year_id' => $fiscalYearId,
        'type' => 'standard',
        'issue_date' => '2026-01-01',
        'due_date' => '2026-01-15',
        'status' => 'issued',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('invoice_lines')->insert([
        'invoice_id' => $invoiceId,
        'line_no' => 1,
        'description' => 'Tuition Fee',
        'collection_basis' => 'own_revenue',
        'revenue_account_id' => ChartOfAccount::factory()->create()->id,
        'recognition_method' => 'on_issue',
        'quantity' => 1,
        'unit_amount' => 100_000,
        'amount' => 100_000,
        'tax_amount' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($user);
    $response = get('/accounting/dashboard')->assertOk();

    $response->assertSeeText('100,000');
    $response->assertSeeText('Aged Debtor');
});
