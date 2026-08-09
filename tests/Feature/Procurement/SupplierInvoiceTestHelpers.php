<?php

declare(strict_types=1);

use App\Modules\Accounting\Actions\SavePostingRule;
use App\Modules\Accounting\Domain\AccountSource;
use App\Modules\Accounting\Domain\LineSign;
use App\Modules\Accounting\Domain\PostingEvent;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Identity\Models\User;
use App\Modules\Procurement\Actions\CaptureSupplierInvoice;
use App\Modules\Procurement\Actions\SaveSupplier;
use App\Modules\Procurement\Domain\ProcurementPermission;
use App\Modules\Procurement\Domain\SupplierInvoicePermission;
use App\Modules\Procurement\Models\Supplier;
use App\Modules\Procurement\Models\SupplierInvoice;
use App\Modules\Tax\Actions\ConfigureWithholdingRule;
use App\Modules\Tax\Models\TaxCode;
use App\Modules\Tax\Models\WithholdingRule;
use App\Support\Audit\Actor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

/**
 * Shared fixtures for the F3 supplier-invoice suites. Prefix f3Inv, every
 * helper function_exists-guarded (00-core test discipline; names must
 * never collide with another agent's).
 *
 * The invoice chain needs the WHOLE tax substrate configured - fiscal
 * identity, TaxSettings recognition basis, a confirmed prorata, at least
 * one confirmed WithholdingRule - because every gap in that substrate is
 * BLOCKING by design (§11.16). f3InvBaseline() stands it all up.
 */
if (! function_exists('f3InvUser')) {
    /** A signed-in user holding exactly the named abilities. */
    function f3InvUser(string ...$permissions): User
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

if (! function_exists('f3InvActor')) {
    function f3InvActor(User $user): Actor
    {
        return $user->toAuditActor();
    }
}

if (! function_exists('f3InvAccountId')) {
    /** A seeded chart account id by code. */
    function f3InvAccountId(string $code): int
    {
        return (int) DB::table('chart_of_accounts')->where('code', $code)->value('id');
    }
}

if (! function_exists('f3InvExpenseAccountId')) {
    /** A postable class-6 account for invoice lines. */
    function f3InvExpenseAccountId(): int
    {
        return (int) DB::table('chart_of_accounts')
            ->where('is_postable', true)
            ->where('code', 'like', '6%')
            ->orderBy('code')
            ->value('id');
    }
}

if (! function_exists('f3InvCapexAccountId')) {
    /** A postable class-2 account for capitalised lines. */
    function f3InvCapexAccountId(): int
    {
        return (int) DB::table('chart_of_accounts')
            ->where('is_postable', true)
            ->where('code', 'like', '2%')
            ->orderBy('code')
            ->value('id');
    }
}

if (! function_exists('f3InvCalendar')) {
    /**
     * Fiscal + academic year + open March period covering 2031-03-15.
     *
     * @return array{fiscal_year_id: int, accounting_period_id: int, academic_year_id: int}
     */
    function f3InvCalendar(string $date = '2031-03-15'): array
    {
        return (new Database\Factories\JournalEntryFactory())->buildCalendar(Carbon::parse($date));
    }
}

if (! function_exists('f3InvIdentity')) {
    /**
     * A confirmed, TVA-registered fiscal identity (the §2 gates).
     *
     * @param  array<string, mixed>  $overrides
     */
    function f3InvIdentity(array $overrides = []): void
    {
        $user = User::factory()->create();

        DB::table('fiscal_identities')->insert([
            'id' => 1,
            'legal_name' => 'Collège Bilingue OPES',
            'legal_form' => 'etablissement_prive_laic',
            'niu' => 'M012345678901C',
            'tax_centre_code' => 'CIME-YDE1',
            'tax_centre_name' => 'CIME Yaoundé 1er',
            'tax_centre_type' => 'CIME',
            'tax_regime' => 'reel',
            'tax_regime_effective_from' => '2020-01-01',
            'is_tva_registered' => true,
            'tva_registered_from' => '2020-01-01',
            'ministry_accreditation_number' => 'ARR-2015-0042',
            'ministry_accreditation_authority' => 'MINESEC',
            'ministry_accreditation_date' => '2015-09-01',
            'ministry_accreditation_expires_on' => null,
            'fiscal_year_end_month' => 12,
            'fiscal_year_end_day' => 31,
            'fiscal_identity_confirmed_by' => $user->getKey(),
            'fiscal_identity_confirmed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ]);
    }
}

if (! function_exists('f3InvTaxSettings')) {
    /** Confirmed TaxSettings with the given recognition basis (§4.6). */
    function f3InvTaxSettings(string $recognition = 'on_invoice'): void
    {
        DB::table('tax_settings')->insert([
            'id' => 1,
            'withholding_recognition' => $recognition,
            'prorata_rounding' => 'exact_bp',
            'confirmed_by' => User::factory()->create()->getKey(),
            'confirmed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

if (! function_exists('f3InvConfirmedProrata')) {
    /** A CONFIRMED provisional prorata for the fiscal year (§5.4). */
    function f3InvConfirmedProrata(int $fiscalYearId, int $rateBp = 11_720): void
    {
        $user = User::factory()->create();

        DB::table('vat_proratas')->insert([
            'fiscal_year_id' => $fiscalYearId,
            'basis' => 'provisional',
            'rate_bp' => $rateBp,
            'numerator_amount' => 34_000_000,
            'denominator_amount' => 290_000_000,
            'computed_at' => now(),
            'computed_by' => $user->getKey(),
            'source' => 'computed',
            'confirmed_by' => $user->getKey(),
            'confirmed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

if (! function_exists('f3InvInputTaxCode')) {
    /**
     * An input-side 19.25% TVA code with its posting accounts wired
     * (deductible → a stand-in 4451 leaf, non-deductible → expense).
     *
     * @param  array<string, mixed>  $overrides
     */
    function f3InvInputTaxCode(array $overrides = []): TaxCode
    {
        return TaxCode::factory()->create([
            'direction' => 'input',
            'rate_bp' => 19_250,
            'deductible_account_id' => ChartOfAccount::factory()->create()->id,
            'non_deductible_expense_account_id' => ChartOfAccount::factory()->create()->id,
            ...$overrides,
        ]);
    }
}

if (! function_exists('f3InvWithholdingRule')) {
    /**
     * A CONFIRMED withholding rule through the real Configure gate.
     *
     * @param  array<string, mixed>  $overrides
     */
    function f3InvWithholdingRule(User $configurer, array $overrides = []): WithholdingRule
    {
        $action = app(ConfigureWithholdingRule::class);

        $rule = $action->handle(null, [
            'code' => 'F3-'.strtoupper(fake()->unique()->lexify('?????')),
            'name' => 'AIR on services (F3)',
            'name_fr' => 'Acompte IR sur prestations (F3)',
            'withholding_type' => 'air',
            'rate_bp' => 5_500,
            'base' => 'amount_ht',
            'applies_to' => 'both',
            'priority' => 10,
            'legal_ref' => 'CGI art. 92 (à vérifier)',
            'effective_from' => '2020-01-01',
            'liability_account_id' => ChartOfAccount::factory()->create()->id,
            ...$overrides,
        ], f3InvActor($configurer));

        return $action->confirm((int) $rule->getKey(), f3InvActor($configurer));
    }
}

if (! function_exists('f3InvSupplier')) {
    /**
     * A supplier through the real SaveSupplier gate; defaults to the
     * seeded 401 operating payable and 30-day terms.
     *
     * @param  array<string, mixed>  $overrides
     */
    function f3InvSupplier(array $overrides = [], ?User $manager = null): Supplier
    {
        $manager ??= f3InvUser(ProcurementPermission::SUPPLIER_MANAGE);

        return app(SaveSupplier::class)->handle($overrides + [
            'name' => 'Fournitures Scolaires '.fake()->unique()->numberBetween(1, 999_999),
            'supplier_type' => 'company',
            'payable_account_id' => f3InvAccountId('401'),
            'payment_terms_days' => 30,
        ], f3InvActor($manager));
    }
}

if (! function_exists('f3InvSavePostingRules')) {
    /**
     * The three §4.6 posting rules, saved through the REAL SavePostingRule
     * gate against the §11.2 catalogue events:
     *
     *  - supplier.invoice.received:      Signed iteration over the payload
     *    legs (expense HT, TVA déductible, TVA non déductible), balancing
     *    Cr on the payable with the supplier partner;
     *  - supplier.credit_note.received:  the mirror - legs arrive negative
     *    (credits), balancing Dr on the payable;
     *  - withholding.retained:           Cr per liability leg, balancing
     *    Dr on the payable (the §4.6 on_invoice recognition).
     */
    function f3InvSavePostingRules(User $accountant): void
    {
        $journal = Journal::factory()->create();
        $save = app(SavePostingRule::class);

        $save->handle([
            'code' => 'f3_supplier_invoice',
            'event' => PostingEvent::SupplierInvoiceReceived->value,
            'journal_id' => $journal->id,
            'label_expression' => 'Facture fournisseur {document.reference}',
            'condition_expression' => null,
            'priority' => 100,
            'is_active' => true,
            'effective_from' => '2030-01-01',
            'effective_to' => null,
        ], [
            [
                'sequence' => 1,
                'account_source' => AccountSource::PayloadPath,
                'account_path' => 'item.expense_account_id',
                'iterates_over' => 'document.lines',
                'sign' => LineSign::Signed,
                'amount_expression' => 'item.amount',
                'label_expression' => '{item.label}',
            ],
            [
                'sequence' => 2,
                'account_source' => AccountSource::PayloadPath,
                'account_path' => 'document.payable_account_id',
                'sign' => LineSign::Credit,
                'amount_expression' => 'document.total',
                'is_balancing' => true,
                'partner_source' => 'document.partner',
                'label_expression' => 'Fournisseur — {document.reference}',
            ],
        ], f3InvActor($accountant));

        $save->handle([
            'code' => 'f3_supplier_credit_note',
            'event' => PostingEvent::SupplierCreditNoteReceived->value,
            'journal_id' => $journal->id,
            'label_expression' => 'Avoir fournisseur {document.reference}',
            'condition_expression' => null,
            'priority' => 100,
            'is_active' => true,
            'effective_from' => '2030-01-01',
            'effective_to' => null,
        ], [
            [
                'sequence' => 1,
                'account_source' => AccountSource::PayloadPath,
                'account_path' => 'item.expense_account_id',
                'iterates_over' => 'document.lines',
                'sign' => LineSign::Signed,
                'amount_expression' => 'item.amount',
                'label_expression' => '{item.label}',
            ],
            [
                'sequence' => 2,
                'account_source' => AccountSource::PayloadPath,
                'account_path' => 'document.payable_account_id',
                'sign' => LineSign::Debit,
                'amount_expression' => 'document.total',
                'is_balancing' => true,
                'partner_source' => 'document.partner',
                'label_expression' => 'Fournisseur — {document.reference}',
            ],
        ], f3InvActor($accountant));

        $save->handle([
            'code' => 'f3_withholding_retained',
            'event' => PostingEvent::WithholdingRetained->value,
            'journal_id' => $journal->id,
            'label_expression' => 'Retenue à la source {document.reference}',
            'condition_expression' => null,
            'priority' => 100,
            'is_active' => true,
            'effective_from' => '2030-01-01',
            'effective_to' => null,
        ], [
            [
                'sequence' => 1,
                'account_source' => AccountSource::PayloadPath,
                'account_path' => 'item.expense_account_id',
                'iterates_over' => 'document.lines',
                'sign' => LineSign::Credit,
                'amount_expression' => 'item.amount',
                'label_expression' => '{item.label}',
            ],
            [
                'sequence' => 2,
                'account_source' => AccountSource::PayloadPath,
                'account_path' => 'document.payable_account_id',
                'sign' => LineSign::Debit,
                'amount_expression' => 'document.total',
                'is_balancing' => true,
                'partner_source' => 'document.partner',
                'label_expression' => 'Fournisseur — {document.reference}',
            ],
        ], f3InvActor($accountant));
    }
}

if (! function_exists('f3InvBaseline')) {
    /**
     * The full substrate: calendar, identity, settings, prorata, one
     * confirmed withholding rule, posting rules, a supplier, an input tax
     * code - and a clerk signed in with the capture permission.
     *
     * @return array{clerk: User, supplier: Supplier, tax_code: TaxCode, rule: WithholdingRule, calendar: array{fiscal_year_id: int, accounting_period_id: int, academic_year_id: int}}
     */
    function f3InvBaseline(string $recognition = 'on_invoice', int $prorataBp = 11_720): array
    {
        $calendar = f3InvCalendar();
        f3InvIdentity();
        f3InvTaxSettings($recognition);
        f3InvConfirmedProrata($calendar['fiscal_year_id'], $prorataBp);

        $configurer = f3InvUser(
            \App\Modules\Identity\Domain\Permission::LedgerConfigure->value,
        );
        $rule = f3InvWithholdingRule($configurer);
        f3InvSavePostingRules($configurer);

        $supplier = f3InvSupplier();
        $taxCode = f3InvInputTaxCode();

        $clerk = f3InvUser(
            SupplierInvoicePermission::VIEW,
            SupplierInvoicePermission::CREATE,
        );

        return [
            'clerk' => $clerk,
            'supplier' => $supplier,
            'tax_code' => $taxCode,
            'rule' => $rule,
            'calendar' => $calendar,
        ];
    }
}

if (! function_exists('f3InvCapture')) {
    /**
     * Capture an invoice with the given lines through the real Action.
     *
     * @param  array<string, mixed>  $header
     * @param  list<array<string, mixed>>  $lines
     */
    function f3InvCapture(User $clerk, Supplier $supplier, array $lines, array $header = []): SupplierInvoice
    {
        /** @var list<array{description: string, quantity?: string, unit_of_measure?: string|null, unit_price_ht: int, discount_rate_bp?: int, tax_code_id: int, expense_account_id: int, purchase_order_line_id?: int|null, goods_receipt_line_id?: int|null, is_capitalised?: bool, asset_category_id?: int|null, asset_id?: int|null, inventory_item_id?: int|null, nature?: string}> $lines */
        return app(CaptureSupplierInvoice::class)->handle($header + [
            'supplier_id' => $supplier->id,
            'supplier_invoice_no' => 'SUP-'.fake()->unique()->numberBetween(1000, 9_999_999),
            'invoice_date' => '2031-03-15',
        ], $lines, f3InvActor($clerk));
    }
}

if (! function_exists('f3InvServiceLine')) {
    /**
     * A plain 1 000 000 HT service line at 19.25% input TVA.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    function f3InvServiceLine(TaxCode $taxCode, array $overrides = []): array
    {
        return $overrides + [
            'description' => 'Consulting services',
            'quantity' => '1',
            'unit_price_ht' => 1_000_000,
            'tax_code_id' => (int) $taxCode->id,
            'expense_account_id' => f3InvExpenseAccountId(),
        ];
    }
}
