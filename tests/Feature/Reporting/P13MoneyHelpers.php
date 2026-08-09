<?php

declare(strict_types=1);

use App\Modules\Accounting\Actions\SavePostingRule;
use App\Modules\Accounting\Domain\AccountSource;
use App\Modules\Accounting\Domain\LineSign;
use App\Modules\Accounting\Domain\PostingEvent;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Fees\Actions\GenerateInvoices;
use App\Modules\Fees\Actions\RecordPayment;
use App\Modules\Fees\Domain\PaymentMethod;
use App\Modules\Fees\Models\Invoice;
use App\Modules\Fees\Models\Payment;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Students\Models\Enrollment;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

/*
 * Shared fixtures for the phase-12-13 D3 money-document suites
 * (ReceiptRenderTest, InvoicePrintTest, AttestationPrintTest). Every helper
 * is p13money-prefixed and function_exists-guarded per the parallel-agent
 * convention. Reuses the already-committed ledgerCalendar()/ledgerUser()
 * (tests/Feature/Accounting/AccountingTestHelpers.php) and the F4 payables
 * chain (tests/Feature/Procurement/SupplierPaymentTestHelpers.php) rather
 * than re-deriving them - both files are required_once by the test files
 * that need them.
 */

if (! function_exists('p13moneyUserAs')) {
    /** A logged-in user holding the union of the given roles' permissions. */
    function p13moneyUserAs(Role ...$roles): User
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

if (! function_exists('p13moneyDocumentProfile')) {
    /**
     * The school_document_profiles singleton (id = 1) - state header OFF,
     * matching the money templates' own state_header = 'none' (10-documents
     * §10 money docs carry no bilingual state letterhead).
     *
     * @param  array<string, mixed>  $overrides
     */
    function p13moneyDocumentProfile(array $overrides = []): void
    {
        DB::table('school_document_profiles')->updateOrInsert(['id' => 1], array_merge([
            'state_header_enabled' => false,
            'ministry_en' => null,
            'ministry_fr' => null,
            'regional_delegation_en' => null,
            'regional_delegation_fr' => null,
            'divisional_delegation_en' => null,
            'divisional_delegation_fr' => null,
            'default_document_language' => 'en',
            'bilingual_documents' => false,
            'crest_path' => null,
            'logo_path' => null,
            'principal_signature_path' => null,
            'registrar_signature_path' => null,
            'school_stamp_path' => null,
            'books_cote_paraphe_reference' => null,
            'paraphe_authority' => null,
            'paraphe_date' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }
}

if (! function_exists('p13moneyConfirmedFiscalIdentity')) {
    /**
     * A confirmed fiscal identity carrying every field
     * FiscalIdentityGate::missingFields() checks - 10-documents §4.7's
     * "refuses to render without a NIU" gate is satisfied by default so
     * each test opts INTO the missing-field case, not out of it.
     *
     * @param  array<string, mixed>  $overrides
     */
    function p13moneyConfirmedFiscalIdentity(array $overrides = []): void
    {
        DB::table('fiscal_identities')->updateOrInsert(['id' => 1], array_merge([
            'legal_name' => 'Hope Academy',
            'legal_form' => 'etablissement_prive_laic',
            'niu' => 'M012345678901A',
            'niu_issued_on' => '2018-01-01',
            'rccm_number' => 'RC/DLA/2018/B/1234',
            'rccm_registry' => 'Douala',
            'rccm_registered_on' => '2018-01-01',
            'tax_centre_code' => 'CIME-DLA1',
            'tax_centre_name' => 'CIME Douala 1er',
            'tax_centre_type' => 'CIME',
            'tax_regime' => 'reel',
            'tax_regime_effective_from' => '2018-01-01',
            'is_tva_registered' => true,
            'tva_registered_from' => '2018-01-01',
            'ministry_accreditation_number' => 'ARR-2018-0099',
            'ministry_accreditation_authority' => 'MINESEC',
            'ministry_accreditation_date' => '2018-01-01',
            'ministry_accreditation_expires_on' => null,
            'ministry_accreditation_document_id' => null,
            'fiscal_year_end_month' => 12,
            'fiscal_year_end_day' => 31,
            'fiscal_identity_confirmed_by' => User::factory()->create()->getKey(),
            'fiscal_identity_confirmed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }
}

if (! function_exists('p13moneyOwnItemId')) {
    /** An own-revenue fee item posting to $revenueCode (706 by default). */
    function p13moneyOwnItemId(string $name, string $revenueCode = '706'): int
    {
        $categoryId = DB::table('fee_categories')->insertGetId([
            'code' => 'P13C'.Str::upper(Str::random(6)),
            'name' => $name.' category',
            'name_fr' => $name.' catégorie',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $accountId = DB::table('chart_of_accounts')->where('code', $revenueCode)->value('id');

        return (int) DB::table('fee_items')->insertGetId([
            'code' => 'P13I'.Str::upper(Str::random(6)),
            'name' => $name,
            'name_fr' => $name.' (fr)',
            'fee_category_id' => $categoryId,
            'collection_basis' => 'own_revenue',
            'revenue_account_id' => $accountId,
            'recognition_method' => 'on_issue',
            'default_recurrence' => 'per_year',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

if (! function_exists('p13moneyInvoiceFixture')) {
    /**
     * One enrollment + an active structure of two own-revenue lines
     * totalling 350 000, on the ledgerCalendar() covering 2031-03-15 - the
     * same shape tests/Feature/Fees/InvoiceTest.php uses, under the
     * p13money prefix so the two suites never collide.
     *
     * @return array{enrollment: Enrollment, options: array{academic_year_id: int, fiscal_year_id: int, term_id: int|null, issue_date: string, due_date: string}}
     */
    function p13moneyInvoiceFixture(array $calendar): array
    {
        /** @var Enrollment $enrollment */
        $enrollment = Enrollment::factory()->create([
            'academic_year_id' => $calendar['academic_year_id'],
        ]);

        $structureId = DB::table('fee_structures')->insertGetId([
            'academic_year_id' => $enrollment->academic_year_id,
            'school_section_id' => $enrollment->school_section_id,
            'name' => 'P13 Money Structure '.Str::upper(Str::random(5)),
            'status' => 'active',
            'effective_from' => '2030-09-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $lines = [
            ['fee_item_id' => p13moneyOwnItemId('Tuition Fee'), 'amount' => 200_000],
            ['fee_item_id' => p13moneyOwnItemId('Development Fee'), 'amount' => 150_000],
        ];

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

        return [
            'enrollment' => $enrollment,
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

if (! function_exists('p13moneySaveInvoiceIssuedRule')) {
    /** Dr the payload's receivable / Cr one line per invoice line (04-fees §11.2). */
    function p13moneySaveInvoiceIssuedRule(User $accountant): void
    {
        app(SavePostingRule::class)->handle([
            'code' => 'p13m_invoice_issued',
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

if (! function_exists('p13moneySaveCashPaymentRule')) {
    /** Dr 57 (Caisse) gross / Cr 4111 gross, partner = student (04-fees §11). */
    function p13moneySaveCashPaymentRule(User $accountant): void
    {
        app(SavePostingRule::class)->handle([
            'code' => 'p13m_cash_payment',
            'event' => PostingEvent::FeePaymentReceived->value,
            'journal_id' => Journal::factory()->create()->id,
            'label_expression' => 'Encaissement espèces réf. {payment.reference}',
            'condition_expression' => null,
            'priority' => 100,
            'is_active' => true,
            'effective_from' => '2030-01-01',
            'effective_to' => null,
        ], [
            [
                'sequence' => 1,
                'account_source' => AccountSource::Literal,
                'account_code' => '57',
                'sign' => LineSign::Debit,
                'amount_expression' => 'payment.amount',
                'label_expression' => 'Encaissement réf. {payment.reference}',
            ],
            [
                'sequence' => 2,
                'account_source' => AccountSource::Literal,
                'account_code' => '4111',
                'sign' => LineSign::Credit,
                'amount_expression' => 'payment.amount',
                'is_balancing' => true,
                'partner_source' => 'payment.partner',
                'label_expression' => '{payment.partner_label} — {payment.reference}',
            ],
        ], $accountant->toAuditActor());
    }
}

if (! function_exists('p13moneyDraftInvoice')) {
    /**
     * @param  array{enrollment: Enrollment, options: array{academic_year_id: int, fiscal_year_id: int, term_id: int|null, issue_date: string, due_date: string}}  $fixture
     */
    function p13moneyDraftInvoice(array $fixture, User $user): Invoice
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

if (! function_exists('p13moneyRecordCash')) {
    function p13moneyRecordCash(int $studentId, ?int $enrollmentId, array $calendar, User $cashier, int $amount = 120_000): Payment
    {
        return app(RecordPayment::class)->handle(
            studentId: $studentId,
            academicYearId: $calendar['academic_year_id'],
            fiscalYearId: $calendar['fiscal_year_id'],
            method: PaymentMethod::Cash,
            amount: Money::of($amount),
            payerName: 'Doe, Jane (mother)',
            valueDate: '2031-03-15',
            actor: $cashier->toAuditActor(),
            enrollmentId: $enrollmentId,
        );
    }
}
