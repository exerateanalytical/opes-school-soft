<?php

declare(strict_types=1);

use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Identity\Models\User;
use App\Modules\Tax\Models\FiscalIdentity;
use App\Modules\Tax\Models\TaxCode;
use App\Modules\Tax\Models\WithholdingRule;
use App\Support\Audit\Actor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

/**
 * Shared fixtures for the F5 declaration suites. Prefix f5Decl, every
 * helper function_exists-guarded (the names must never collide with
 * another agent's helper file).
 */
if (! function_exists('f5DeclUser')) {
    /** A signed-in user holding exactly the named abilities. */
    function f5DeclUser(string ...$permissions): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $permission) {
            \Spatie\Permission\Models\Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }

        $user = $user->fresh() ?? $user;
        actingAs($user);

        return $user;
    }
}

if (! function_exists('f5DeclActor')) {
    function f5DeclActor(User $user): Actor
    {
        return $user->toAuditActor();
    }
}

if (! function_exists('f5DeclCalendar')) {
    /**
     * Fiscal + academic year + one accounting period covering the date
     * (Accounting's own factory builder).
     *
     * @return array{fiscal_year_id: int, accounting_period_id: int, academic_year_id: int}
     */
    function f5DeclCalendar(string $date = '2031-03-15'): array
    {
        return (new Database\Factories\JournalEntryFactory())->buildCalendar(Carbon::parse($date));
    }
}

if (! function_exists('f5DeclAddPeriod')) {
    /** A further accounting period on an existing fiscal year. */
    function f5DeclAddPeriod(int $fiscalYearId, string $month, string $status = 'open'): AccountingPeriod
    {
        $start = Carbon::parse($month.'-01');

        return AccountingPeriod::factory()->create([
            'fiscal_year_id' => $fiscalYearId,
            'period_month' => $start->toDateString(),
            'starts_on' => $start->toDateString(),
            'ends_on' => $start->copy()->endOfMonth()->toDateString(),
            'status' => $status,
        ]);
    }
}

if (! function_exists('f5DeclLockPeriod')) {
    function f5DeclLockPeriod(int $accountingPeriodId, string $status = 'soft_locked'): void
    {
        DB::table('accounting_periods')->where('id', $accountingPeriodId)->update(['status' => $status]);
    }
}

if (! function_exists('f5DeclAccount')) {
    /** A postable throwaway leaf account (class-9 branch). */
    function f5DeclAccount(): ChartOfAccount
    {
        return ChartOfAccount::factory()->create();
    }
}

if (! function_exists('f5DeclPostEntry')) {
    /**
     * Posts a balanced JournalEntry directly against the schema (draft →
     * lines → posted, the order the L3 trigger requires) - same shape as
     * the Accounting suites' postDirectEntry, under the f5 prefix.
     *
     * @param  array{fiscal_year_id: int, accounting_period_id: int, academic_year_id: int}  $calendar
     * @param  list<array{account_id: int, debit: int, credit: int}>  $lines
     */
    function f5DeclPostEntry(array $calendar, string $date, string $pieceNo, array $lines): JournalEntry
    {
        $journal = Journal::factory()->create();

        /** @var JournalEntry $entry */
        $entry = JournalEntry::query()->create([
            'journal_id' => $journal->id,
            'piece_no' => null,
            'date' => $date,
            'value_date' => $date,
            'accounting_period_id' => $calendar['accounting_period_id'],
            'fiscal_year_id' => $calendar['fiscal_year_id'],
            'academic_year_id' => $calendar['academic_year_id'],
            'label' => 'F5 test entry '.$pieceNo,
            'status' => JournalEntry::STATUS_DRAFT,
            'total_debit' => 0,
            'total_credit' => 0,
        ]);

        foreach ($lines as $sequence => $line) {
            JournalEntryLine::query()->create([
                'journal_entry_id' => $entry->id,
                'sequence' => $sequence + 1,
                'account_id' => $line['account_id'],
                'label' => 'Line',
                'debit' => $line['debit'],
                'credit' => $line['credit'],
            ]);
        }

        $entry->forceFill([
            'status' => JournalEntry::STATUS_POSTED,
            'piece_no' => $pieceNo,
            'total_debit' => array_sum(array_column($lines, 'debit')),
            'total_credit' => array_sum(array_column($lines, 'credit')),
        ])->save();

        return $entry->fresh() ?? $entry;
    }
}

if (! function_exists('f5DeclConfirmedIdentity')) {
    /**
     * The confirmed fiscal-identity singleton.
     *
     * @param  array<string, mixed>  $overrides
     */
    function f5DeclConfirmedIdentity(array $overrides = []): FiscalIdentity
    {
        $identity = FiscalIdentity::query()->find(FiscalIdentity::SINGLETON_ID) ?? new FiscalIdentity();

        $identity->forceFill($overrides + [
            'id' => FiscalIdentity::SINGLETON_ID,
            'legal_name' => 'Collège Bilingue de la Falaise',
            'legal_form' => 'association',
            'niu' => 'M012345678901A',
            'tax_centre_code' => 'C-042',
            'tax_centre_name' => 'CIME Douala 1er',
            'tax_centre_type' => 'DGE',
            'tax_regime' => 'reel',
            'is_tva_registered' => true,
            'fiscal_year_end_month' => 12,
            'fiscal_year_end_day' => 31,
            'fiscal_identity_confirmed_by' => User::factory()->create()->id,
            'fiscal_identity_confirmed_at' => now(),
        ])->save();

        return $identity->refresh();
    }
}

if (! function_exists('f5DeclType')) {
    /** A tax_declaration_types reference row; mapped = form boxes verified. */
    function f5DeclType(string $code, string $periodType = 'month', bool $mapped = true): int
    {
        $existing = DB::table('tax_declaration_types')->where('code', $code)->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        return (int) DB::table('tax_declaration_types')->insertGetId([
            'code' => $code,
            'name' => 'Test type '.$code,
            'name_fr' => 'Type de test '.$code,
            'period_type' => $periodType,
            'form_boxes' => $mapped ? json_encode(['TVA_OUTPUT' => 'A1', 'TVA_INPUT_DEDUCTIBLE' => 'B4', 'WH_TOTAL' => 'C1']) : null,
            'is_archived' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

if (! function_exists('f5DeclTaxCode')) {
    /** An active TVA code carrying collected + deductible accounts. */
    function f5DeclTaxCode(int $collectedAccountId, int $deductibleAccountId): TaxCode
    {
        return TaxCode::factory()->create([
            'direction' => 'both',
            'collected_account_id' => $collectedAccountId,
            'deductible_account_id' => $deductibleAccountId,
        ]);
    }
}

if (! function_exists('f5DeclRule')) {
    /** A CONFIRMED withholding rule with a liability (447) account. */
    function f5DeclRule(int $liabilityAccountId, int $rateBp = 5_500): WithholdingRule
    {
        $confirmer = User::factory()->create();

        return WithholdingRule::query()->create([
            'code' => 'WR'.strtoupper(fake()->unique()->lexify('????')),
            'name' => 'Test withholding rule',
            'name_fr' => 'Règle de retenue de test',
            'withholding_type' => 'air',
            'rate_bp' => $rateBp,
            'base' => 'amount_ht',
            'applies_to' => 'both',
            'minimum_base' => 0,
            'priority' => 100,
            'liability_account_id' => $liabilityAccountId,
            'effective_from' => '2020-01-01',
            'is_active' => true,
            'confirmed_by' => $confirmer->id,
            'confirmed_at' => now(),
        ]);
    }
}

if (! function_exists('f5DeclSupplier')) {
    /** A minimal supplier row via the query builder (annex snapshots). */
    function f5DeclSupplier(string $name, ?string $niu = null): int
    {
        $creator = User::factory()->create();
        $payable = (int) DB::table('chart_of_accounts')->where('code', '401')->value('id');

        return (int) DB::table('suppliers')->insertGetId([
            'code' => 'FRS-'.strtoupper(fake()->unique()->lexify('?????')),
            'name' => $name,
            'supplier_type' => 'company',
            'niu' => $niu,
            'payable_account_id' => $payable,
            'created_by' => $creator->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

if (! function_exists('f5DeclInvoiceRow')) {
    /**
     * A minimal posted supplier-invoice header via the query builder -
     * just enough for an attestation's XOR source FK.
     *
     * @param  array{fiscal_year_id: int, accounting_period_id: int, academic_year_id: int}  $calendar
     */
    function f5DeclInvoiceRow(int $supplierId, array $calendar, string $date = '2031-03-10'): int
    {
        $creator = User::factory()->create();
        $payable = (int) DB::table('chart_of_accounts')->where('code', '401')->value('id');

        return (int) DB::table('supplier_invoices')->insertGetId([
            'internal_no' => 'FF/TEST/'.str_pad((string) fake()->unique()->numberBetween(1, 999_999), 6, '0', STR_PAD_LEFT),
            'supplier_invoice_no' => 'SUP-'.fake()->unique()->numberBetween(1, 999_999),
            'supplier_id' => $supplierId,
            'invoice_date' => $date,
            'received_date' => $date,
            'value_date' => $date,
            'due_date' => Carbon::parse($date)->addDays(30)->toDateString(),
            'payable_account_id' => $payable,
            'created_by' => $creator->id,
            'academic_year_id' => $calendar['academic_year_id'],
            'fiscal_year_id' => $calendar['fiscal_year_id'],
            'accounting_period_id' => $calendar['accounting_period_id'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

if (! function_exists('f5DeclAttestation')) {
    /**
     * An ISSUED attestation row (snapshot columns filled directly - the
     * issue Action is F1's and exercised by its own suite). Sourced from a
     * supplier invoice (the §6.6 XOR demands exactly one source).
     *
     * @param  array<string, mixed>  $overrides
     */
    function f5DeclAttestation(int $supplierId, int $invoiceId, int $ruleId, int $year, int $month, int $base, int $rateBp, int $withheld, array $overrides = []): \App\Modules\Tax\Models\WithholdingAttestation
    {
        $creator = User::factory()->create();

        return \App\Modules\Tax\Models\WithholdingAttestation::query()->create($overrides + [
            'attestation_no' => sprintf('ATT/%d/%06d', $year, fake()->unique()->numberBetween(1, 999_999)),
            'supplier_id' => $supplierId,
            'supplier_invoice_id' => $invoiceId,
            'supplier_payment_id' => null,
            'withholding_rule_id' => $ruleId,
            'period_month' => $month,
            'period_year' => $year,
            'base_amount' => $base,
            'rate_bp_applied' => $rateBp,
            'withheld_amount' => $withheld,
            'status' => 'issued',
            'issued_at' => now(),
            'issued_by' => $creator->id,
            'created_by' => $creator->id,
        ]);
    }
}
