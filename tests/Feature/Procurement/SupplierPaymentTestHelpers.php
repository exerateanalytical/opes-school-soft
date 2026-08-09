<?php

declare(strict_types=1);

use App\Modules\Accounting\Actions\SavePostingRule;
use App\Modules\Accounting\Domain\AccountSource;
use App\Modules\Accounting\Domain\LineSign;
use App\Modules\Accounting\Domain\PostingEvent;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Identity\Models\User;
use App\Modules\Procurement\Actions\ApproveSupplierInvoice;
use App\Modules\Procurement\Actions\ApproveSupplierPayment;
use App\Modules\Procurement\Actions\CaptureSupplierInvoice;
use App\Modules\Procurement\Actions\MatchSupplierInvoice;
use App\Modules\Procurement\Actions\PaySupplierPayment;
use App\Modules\Procurement\Actions\PostSupplierInvoice;
use App\Modules\Procurement\Actions\RecordSupplierPayment;
use App\Modules\Procurement\Actions\SaveSupplier;
use App\Modules\Procurement\Domain\ProcurementPermission;
use App\Modules\Procurement\Domain\SupplierInvoicePermission;
use App\Modules\Procurement\Domain\SupplierPaymentPermission;
use App\Modules\Procurement\Models\Supplier;
use App\Modules\Procurement\Models\SupplierInvoice;
use App\Modules\Procurement\Models\SupplierPayment;
use App\Modules\Tax\Actions\ConfigureWithholdingRule;
use App\Modules\Tax\Models\TaxCode;
use App\Modules\Tax\Models\WithholdingRule;
use App\Support\Audit\Actor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

/**
 * Shared fixtures for the F4 payables suites. Prefix f4Pay, every helper
 * function_exists-guarded (00-core test discipline; names must never
 * collide with another agent's).
 *
 * The payment chain needs the FULL invoice substrate (F1 tax config + F3
 * capture/post) plus the payment-side posting rules. f4PayBaseline()
 * stands everything up; the §6.4 example rides on a 100% prorata so the
 * TVA figures land exactly as the spec prints them.
 */
if (! function_exists('f4PayUser')) {
    /** A signed-in user holding exactly the named abilities. */
    function f4PayUser(string ...$permissions): User
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

if (! function_exists('f4PayActor')) {
    function f4PayActor(User $user): Actor
    {
        return $user->toAuditActor();
    }
}

if (! function_exists('f4PayAccountId')) {
    /** A seeded chart account id by code. */
    function f4PayAccountId(string $code): int
    {
        return (int) DB::table('chart_of_accounts')->where('code', $code)->value('id');
    }
}

if (! function_exists('f4PayExpenseAccountId')) {
    /** A postable class-6 account for invoice lines. */
    function f4PayExpenseAccountId(): int
    {
        return (int) DB::table('chart_of_accounts')
            ->where('is_postable', true)
            ->where('code', 'like', '6%')
            ->orderBy('code')
            ->value('id');
    }
}

if (! function_exists('f4PayCalendar')) {
    /**
     * Fiscal + academic year + open March period covering 2031-03-15.
     *
     * @return array{fiscal_year_id: int, accounting_period_id: int, academic_year_id: int}
     */
    function f4PayCalendar(string $date = '2031-03-15'): array
    {
        return (new Database\Factories\JournalEntryFactory())->buildCalendar(Carbon::parse($date));
    }
}

if (! function_exists('f4PayIdentity')) {
    /** A confirmed, TVA-registered fiscal identity (the §2 gates). */
    function f4PayIdentity(): void
    {
        $user = User::factory()->create();

        DB::table('fiscal_identities')->insert([
            'id' => 1,
            'legal_name' => 'Collège Bilingue OPES',
            'legal_form' => 'etablissement_prive_laic',
            'niu' => 'M098765432109C',
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
        ]);
    }
}

if (! function_exists('f4PayTaxSettings')) {
    /** Confirmed TaxSettings with the given recognition basis (§4.6). */
    function f4PayTaxSettings(string $recognition = 'on_payment'): void
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

if (! function_exists('f4PayProrata')) {
    /** A CONFIRMED prorata; default 100% so §6.4 TVA is fully deductible. */
    function f4PayProrata(int $fiscalYearId, int $rateBp = 100_000): void
    {
        $user = User::factory()->create();

        DB::table('vat_proratas')->insert([
            'fiscal_year_id' => $fiscalYearId,
            'basis' => 'provisional',
            'rate_bp' => $rateBp,
            'numerator_amount' => 100,
            'denominator_amount' => 100,
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

if (! function_exists('f4PayInputTaxCode')) {
    /**
     * An input-side TVA code with its posting accounts wired.
     *
     * @param  array<string, mixed>  $overrides
     */
    function f4PayInputTaxCode(array $overrides = []): TaxCode
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

if (! function_exists('f4PayWithholdingRule')) {
    /**
     * A CONFIRMED 5.5% AIR rule on amount_ht through the real gate.
     *
     * @param  array<string, mixed>  $overrides
     */
    function f4PayWithholdingRule(User $configurer, array $overrides = []): WithholdingRule
    {
        $action = app(ConfigureWithholdingRule::class);

        $rule = $action->handle(null, [
            'code' => 'F4-'.strtoupper(fake()->unique()->lexify('?????')),
            'name' => 'AIR on services (F4)',
            'name_fr' => 'Acompte IR sur prestations (F4)',
            'withholding_type' => 'air',
            'rate_bp' => 5_500,
            'base' => 'amount_ht',
            'applies_to' => 'both',
            'priority' => 10,
            'legal_ref' => 'CGI art. 92 (à vérifier)',
            'effective_from' => '2020-01-01',
            'liability_account_id' => ChartOfAccount::factory()->create()->id,
            ...$overrides,
        ], f4PayActor($configurer));

        return $action->confirm((int) $rule->getKey(), f4PayActor($configurer));
    }
}

if (! function_exists('f4PaySupplier')) {
    /**
     * A supplier through the real SaveSupplier gate; 401 payable default.
     *
     * @param  array<string, mixed>  $overrides
     */
    function f4PaySupplier(array $overrides = []): Supplier
    {
        $manager = f4PayUser(ProcurementPermission::SUPPLIER_MANAGE);

        return app(SaveSupplier::class)->handle($overrides + [
            'name' => 'Prestataire OPES '.fake()->unique()->numberBetween(1, 999_999),
            'supplier_type' => 'company',
            'payable_account_id' => f4PayAccountId('401'),
            'payment_terms_days' => 30,
        ], f4PayActor($manager));
    }
}

if (! function_exists('f4PaySavePostingRules')) {
    /**
     * The full payables rule set through the REAL SavePostingRule gate.
     * Where two rules share an event they are discriminated by the sign of
     * `document.total` - rules are school data, and a negative sentinel is
     * how this school routes the §3.3 retention/accrual mirrors:
     *
     *  supplier.invoice.received  >0: Dr legs / Cr payable (partner)
     *                             <0: retention RELEASE - Dr 4817 (partner
     *                                 leg) / Cr payable (partner)
     *  supplier.paid              >0: settlement - Signed legs (Cr treasury
     *                                 net, Cr 447, Dr/Cr fee) / balancing
     *                                 Dr payable gross (partner)
     *                             <0: retention WITHHOLD - Cr 4817 (partner
     *                                 leg) / balancing Dr payable (partner)
     *  withholding.retained          on_invoice recognition (F3 shape)
     *  goods.received_not_invoiced >0: accrual - Dr expense legs /
     *                                 Cr 4818 (partner)
     *                             <0: first-day reversal - Cr expense legs /
     *                                 Dr 4818 (partner)
     */
    function f4PaySavePostingRules(User $accountant): void
    {
        $journal = Journal::factory()->create();
        $save = app(SavePostingRule::class);
        $actor = f4PayActor($accountant);

        $legsSigned = [
            'sequence' => 1,
            'account_source' => AccountSource::PayloadPath,
            'account_path' => 'item.expense_account_id',
            'iterates_over' => 'document.lines',
            'sign' => LineSign::Signed,
            'amount_expression' => 'item.amount',
            'label_expression' => '{item.label}',
        ];

        $save->handle([
            'code' => 'f4_supplier_invoice',
            'event' => PostingEvent::SupplierInvoiceReceived->value,
            'journal_id' => $journal->id,
            'label_expression' => 'Facture fournisseur {document.reference}',
            'condition_expression' => 'document.total > 0',
            'priority' => 100,
            'is_active' => true,
            'effective_from' => '2030-01-01',
            'effective_to' => null,
        ], [
            $legsSigned,
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
        ], $actor);

        $save->handle([
            'code' => 'f4_retention_release',
            'event' => PostingEvent::SupplierInvoiceReceived->value,
            'journal_id' => $journal->id,
            'label_expression' => 'Libération retenue {document.reference}',
            'condition_expression' => 'document.total < 0',
            // Higher priority + disjoint condition: the negative sentinel
            // selects this rule, positive documents fall through to the
            // invoice rule (SavePostingRule refuses equal priorities).
            'priority' => 110,
            'is_active' => true,
            'effective_from' => '2030-01-01',
            'effective_to' => null,
        ], [
            [
                'sequence' => 1,
                'account_source' => AccountSource::PayloadPath,
                'account_path' => 'item.expense_account_id',
                'iterates_over' => 'document.lines',
                'sign' => LineSign::Debit,
                'amount_expression' => 'item.amount',
                'partner_source' => 'document.partner',
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
        ], $actor);

        $save->handle([
            'code' => 'f4_withholding_retained',
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
        ], $actor);

        $save->handle([
            'code' => 'f4_supplier_paid',
            'event' => PostingEvent::SupplierPaid->value,
            'journal_id' => $journal->id,
            'label_expression' => 'Règlement fournisseur {document.reference}',
            'condition_expression' => 'document.total > 0',
            'priority' => 100,
            'is_active' => true,
            'effective_from' => '2030-01-01',
            'effective_to' => null,
        ], [
            $legsSigned,
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
        ], $actor);

        $save->handle([
            'code' => 'f4_retention_withhold',
            'event' => PostingEvent::SupplierPaid->value,
            'journal_id' => $journal->id,
            'label_expression' => 'Retenue de garantie {document.reference}',
            'condition_expression' => 'document.total < 0',
            'priority' => 110,
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
                'partner_source' => 'document.partner',
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
        ], $actor);

        $save->handle([
            'code' => 'f4_purchase_accrual',
            'event' => PostingEvent::GoodsReceivedNotInvoiced->value,
            'journal_id' => $journal->id,
            'label_expression' => 'Factures non parvenues {document.reference}',
            'condition_expression' => 'document.total > 0',
            'priority' => 100,
            'is_active' => true,
            'effective_from' => '2030-01-01',
            'effective_to' => null,
        ], [
            $legsSigned,
            [
                'sequence' => 2,
                'account_source' => AccountSource::PayloadPath,
                'account_path' => 'document.payable_account_id',
                'sign' => LineSign::Credit,
                'amount_expression' => 'document.total',
                'is_balancing' => true,
                'partner_source' => 'document.partner',
                'label_expression' => 'FNP — {document.reference}',
            ],
        ], $actor);

        $save->handle([
            'code' => 'f4_purchase_accrual_reversal',
            'event' => PostingEvent::GoodsReceivedNotInvoiced->value,
            'journal_id' => $journal->id,
            'label_expression' => 'Extourne FNP {document.reference}',
            'condition_expression' => 'document.total < 0',
            'priority' => 110,
            'is_active' => true,
            'effective_from' => '2030-01-01',
            'effective_to' => null,
        ], [
            $legsSigned,
            [
                'sequence' => 2,
                'account_source' => AccountSource::PayloadPath,
                'account_path' => 'document.payable_account_id',
                'sign' => LineSign::Debit,
                'amount_expression' => 'document.total',
                'is_balancing' => true,
                'partner_source' => 'document.partner',
                'label_expression' => 'FNP — {document.reference}',
            ],
        ], $actor);
    }
}

if (! function_exists('f4PayBaseline')) {
    /**
     * The full substrate: calendar, identity, settings, 100% prorata, one
     * confirmed AIR rule, ALL payables posting rules, a supplier, an input
     * tax code.
     *
     * @return array{supplier: Supplier, tax_code: TaxCode, rule: WithholdingRule, calendar: array{fiscal_year_id: int, accounting_period_id: int, academic_year_id: int}}
     */
    function f4PayBaseline(string $recognition = 'on_payment', int $prorataBp = 100_000): array
    {
        $calendar = f4PayCalendar();
        f4PayIdentity();
        f4PayTaxSettings($recognition);
        f4PayProrata($calendar['fiscal_year_id'], $prorataBp);

        $configurer = f4PayUser(\App\Modules\Identity\Domain\Permission::LedgerConfigure->value);
        $rule = f4PayWithholdingRule($configurer);
        f4PaySavePostingRules($configurer);

        $supplier = f4PaySupplier();
        $taxCode = f4PayInputTaxCode();

        return [
            'supplier' => $supplier,
            'tax_code' => $taxCode,
            'rule' => $rule,
            'calendar' => $calendar,
        ];
    }
}

if (! function_exists('f4PayPostedInvoice')) {
    /**
     * Capture → match → approve → post one invoice through the REAL F3
     * chain, returning it refreshed.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $header
     */
    function f4PayPostedInvoice(Supplier $supplier, array $lines, array $header = []): SupplierInvoice
    {
        $clerk = f4PayUser(SupplierInvoicePermission::VIEW, SupplierInvoicePermission::CREATE);

        /** @var list<array{description: string, quantity?: string, unit_of_measure?: string|null, unit_price_ht: int, discount_rate_bp?: int, tax_code_id: int, expense_account_id: int, purchase_order_line_id?: int|null, goods_receipt_line_id?: int|null, is_capitalised?: bool, asset_category_id?: int|null, asset_id?: int|null, inventory_item_id?: int|null, nature?: string}> $lines */
        $invoice = app(CaptureSupplierInvoice::class)->handle($header + [
            'supplier_id' => $supplier->id,
            'supplier_invoice_no' => 'F4-'.fake()->unique()->numberBetween(1000, 9_999_999),
            'invoice_date' => '2031-03-15',
        ], $lines, f4PayActor($clerk));

        app(MatchSupplierInvoice::class)->handle($invoice->id, f4PayActor($clerk));

        $poster = f4PayUser(
            SupplierInvoicePermission::APPROVE,
            SupplierInvoicePermission::APPROVE_UNMATCHED,
            SupplierInvoicePermission::WAIVE_WITHHOLDING,
            \App\Modules\Identity\Domain\Permission::LedgerPost->value,
        );
        app(ApproveSupplierInvoice::class)->handle($invoice->id, f4PayActor($poster), 'direct purchase, below threshold', 'no rule matched - waived for test');
        app(PostSupplierInvoice::class)->handle($invoice->id, f4PayActor($poster));

        return $invoice->refresh();
    }
}

if (! function_exists('f4PayServiceLine')) {
    /**
     * A plain 1 200 000 HT service line at 19.25% input TVA - the §6.4
     * worked-example line.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    function f4PayServiceLine(TaxCode $taxCode, array $overrides = []): array
    {
        return $overrides + [
            'description' => 'IT consulting',
            'quantity' => '1',
            'unit_price_ht' => 1_200_000,
            'tax_code_id' => (int) $taxCode->id,
            'expense_account_id' => f4PayExpenseAccountId(),
        ];
    }
}

if (! function_exists('f4PayRecordDraft')) {
    /**
     * Record a payment draft through the real gate as a fresh recorder.
     *
     * @param  list<array{supplier_invoice_id: int, amount: int}>  $allocations
     * @param  array<string, mixed>  $overrides
     * @return array{payment: SupplierPayment, recorder: User}
     */
    function f4PayRecordDraft(Supplier $supplier, array $allocations, array $overrides = []): array
    {
        $recorder = f4PayUser(SupplierPaymentPermission::RECORD);

        $payment = app(RecordSupplierPayment::class)->handle($overrides + [
            'supplier_id' => (int) $supplier->id,
            'payment_method' => 'bank',
            'treasury_account_id' => f4PayAccountId('52'),
            'payment_date' => '2031-03-20',
            'reference' => 'VIR-'.fake()->unique()->numberBetween(1000, 999_999),
            'allocations' => $allocations,
        ], f4PayActor($recorder));

        return ['payment' => $payment, 'recorder' => $recorder];
    }
}

if (! function_exists('f4PayApproveAndPay')) {
    /**
     * Walk a draft through approval (fresh approver) and execution (fresh
     * payer with ledger.post for lettering + attestations).
     *
     * @return array{payment: SupplierPayment, approver: User, payer: User}
     */
    function f4PayApproveAndPay(SupplierPayment $payment): array
    {
        $approver = f4PayUser(SupplierPaymentPermission::APPROVE);
        app(ApproveSupplierPayment::class)->handle((int) $payment->getKey(), f4PayActor($approver));

        $payer = f4PayUser(
            SupplierPaymentPermission::RECORD,
            \App\Modules\Identity\Domain\Permission::LedgerPost->value,
        );
        $paid = app(PaySupplierPayment::class)->handle((int) $payment->getKey(), f4PayActor($payer));

        return ['payment' => $paid, 'approver' => $approver, 'payer' => $payer];
    }
}

if (! function_exists('f4PayRow')) {
    /**
     * Narrow a nullable query/collection result to the row it must be -
     * failing the test loudly (not with a null deref) when it is missing.
     */
    function f4PayRow(?object $row): stdClass
    {
        if (! $row instanceof stdClass) {
            throw new RuntimeException('Expected a row, found none - the fixture did not produce it.');
        }

        return $row;
    }
}

if (! function_exists('f4PayEntryLines')) {
    /**
     * The (account code, debit, credit) triples of an entry, ordered.
     *
     * @return list<array{code: string, debit: int, credit: int}>
     */
    function f4PayEntryLines(int $entryId): array
    {
        $rows = DB::table('journal_entry_lines as l')
            ->join('chart_of_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.journal_entry_id', $entryId)
            ->orderBy('l.id')
            ->get(['a.code', 'l.debit', 'l.credit']);

        $lines = [];

        foreach ($rows as $row) {
            $lines[] = ['code' => (string) $row->code, 'debit' => (int) $row->debit, 'credit' => (int) $row->credit];
        }

        return $lines;
    }
}
