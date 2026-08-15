<?php

namespace App\Providers;

use App\Modules\Academics\Livewire\ClassGroups\Index as ClassGroupsIndex;
use App\Modules\Academics\Livewire\Reports\Index as AcademicReportsIndex;
use App\Modules\Academics\Livewire\Timetable\Index as TimetableIndex;
use App\Modules\Accounting\Livewire\ChartOfAccounts\Index as ChartOfAccountsIndex;
use App\Modules\Accounting\Livewire\JournalEntries\Form as JournalEntryForm;
use App\Modules\Accounting\Livewire\JournalEntries\Index as JournalEntriesIndex;
use App\Modules\Accounting\Livewire\Expenses\Index as ExpensesIndex;
use App\Modules\Accounting\Livewire\FinanceDashboard;
use App\Modules\Accounting\Livewire\Expenses\Show as ExpensesShow;
use App\Modules\Accounting\Livewire\Reports\Index as AccountingReportsIndex;
use App\Modules\Accounting\Livewire\Statements\Index as AccountingStatementsIndex;
use App\Modules\Accounting\Livewire\Reports\TrialBalance as TrialBalanceReport;
use App\Modules\Admissions\Livewire\Index as AdmissionsIndex;
use App\Modules\Alumni\Livewire\Index as AlumniIndex;
use App\Modules\Alumni\Livewire\Show as AlumniShow;
use App\Modules\Admissions\Livewire\Wizard as AdmissionsWizard;
use App\Modules\Identity\Livewire\AuditLog\Index as AuditLogIndex;
use App\Modules\Assets\Livewire\Index as AssetsIndex;
use App\Modules\Assets\Livewire\Reports\Index as AssetsReportsIndex;
use App\Modules\Assets\Livewire\Show as AssetsShow;
use App\Modules\Tax\Livewire\Declarations\Index as TaxDeclarationsIndex;
use App\Modules\Tax\Livewire\Reports\Index as TaxReportsIndex;
use App\Modules\Assessment\Livewire\Examinations\Index as ExaminationsIndex;
use App\Modules\Assessment\Livewire\Homework\Index as AssessmentHomeworkIndex;
use App\Modules\Assessment\Livewire\Examinations\Show as ExaminationsShow;
use App\Modules\Assessment\Livewire\Marks\Entry as MarksEntry;
use App\Modules\Assessment\Livewire\Results\Index as ResultsIndex;
use App\Modules\Academics\Livewire\Settings\AcademicSettings;
use App\Modules\Academics\Livewire\Subjects\Index as SubjectsIndex;
use App\Modules\Attendance\Livewire\CoverageReport as AttendanceCoverageReport;
use App\Modules\Attendance\Livewire\Index as AttendanceIndex;
use App\Modules\Attendance\Livewire\TakeRegister as AttendanceTakeRegister;
use App\Modules\Fees\Livewire\CashDesk\Show as FeesCashDeskShow;
use App\Modules\Fees\Livewire\Cashier as FeesCashier;
use App\Modules\Fees\Livewire\Invoices\Index as FeesInvoicesIndex;
use App\Modules\Fees\Livewire\Reports\Index as FeesReportsIndex;
use App\Modules\Fees\Livewire\Statement as FeesStatement;
use App\Modules\Guardians\Livewire\Guardians\Index as GuardiansIndex;
use App\Modules\Guardians\Livewire\Meetings\Index as GuardianMeetingsIndex;
use App\Modules\Guardians\Livewire\Pta\Index as GuardianPtaIndex;
use App\Modules\Guardians\Livewire\Guardians\Show as GuardiansShow;
use App\Modules\Guardians\Livewire\Students\GuardiansPanel as StudentGuardiansPanel;
use App\Modules\HR\Livewire\Index as HrIndex;
use App\Modules\HR\Livewire\Reports\Index as HrReportsIndex;
use App\Modules\Procurement\Livewire\Reports\Index as ProcurementReportsIndex;
use App\Modules\Identity\Livewire\Users\Index as UsersIndex;
use App\Modules\Identity\Livewire\Users\Tokens as UserTokens;
use App\Modules\Inventory\Livewire\Index as InventoryIndex;
use App\Modules\Inventory\Livewire\Show as InventoryShow;
use App\Modules\Library\Livewire\BookShow as LibraryBookShow;
use App\Modules\Library\Livewire\Index as LibraryIndex;
use App\Modules\Library\Livewire\MemberShow as LibraryMemberShow;
use App\Modules\Library\Livewire\Reports\Index as LibraryReportsIndex;
use App\Modules\Payroll\Livewire\Index as PayrollIndex;
use App\Modules\Payroll\Livewire\Show as PayrollShow;
use App\Modules\Procurement\Livewire\GoodsReceipts\Index as GoodsReceiptsIndex;
use App\Modules\Procurement\Livewire\PayablesDashboard;
use App\Modules\Procurement\Livewire\Payments\Index as ProcurementPaymentsIndex;
use App\Modules\Procurement\Livewire\Payments\Pay as ProcurementPaymentsPay;
use App\Modules\Procurement\Livewire\Payments\Show as ProcurementPaymentsShow;
use App\Modules\Procurement\Livewire\PurchaseOrders\Edit as PurchaseOrdersEdit;
use App\Modules\Procurement\Livewire\PurchaseOrders\Index as PurchaseOrdersIndex;
use App\Modules\Procurement\Livewire\PurchaseOrders\Show as PurchaseOrdersShow;
use App\Modules\Procurement\Livewire\Requisitions\Index as RequisitionsIndex;
use App\Modules\Procurement\Livewire\SupplierInvoices\Capture as SupplierInvoicesCapture;
use App\Modules\Procurement\Livewire\SupplierInvoices\Index as SupplierInvoicesIndex;
use App\Modules\Procurement\Livewire\SupplierInvoices\Show as SupplierInvoicesShow;
use App\Modules\Procurement\Livewire\Suppliers\Index as SuppliersIndex;
use App\Modules\Procurement\Livewire\Suppliers\Show as SuppliersShow;
use App\Modules\Accounting\Livewire\Books\Index as AccountingBooksIndex;
use App\Modules\Reporting\Livewire\GlobalSearch\Index as ReportingGlobalSearchIndex;
use App\Modules\Reporting\Livewire\Webhooks\Index as ReportingWebhooksIndex;
use App\Modules\Accounting\Livewire\SystemDocumentation\Index as AccountingSystemDocumentationIndex;
use App\Modules\Accounting\Livewire\Budgets\Index as AccountingBudgetsIndex;
use App\Modules\Accounting\Livewire\Reconciliation\Index as AccountingReconciliationIndex;
use App\Modules\Communication\Livewire\Messages\Index as CommunicationMessagesIndex;
use App\Modules\Notifications\Livewire\Bell as NotificationsBell;
use App\Modules\Forms\Livewire\UnfinishedWork as FormsUnfinishedWork;
use App\Modules\Communication\Livewire\Outbox\Index as CommunicationOutboxIndex;
use App\Modules\Communication\Livewire\Templates\Index as CommunicationTemplatesIndex;
use App\Modules\Operations\Livewire\Backups\Index as OperationsBackupsIndex;
use App\Modules\Operations\Livewire\Setup\Index as OperationsSetupIndex;
use App\Modules\Reporting\Livewire\BulkPrints\Index as BulkPrintsIndex;
use App\Modules\Reporting\Livewire\Reports\Hub as ReportsHub;
use App\Modules\SchoolProfile\Livewire\Index as SettingsIndex;
use App\Modules\Students\Livewire\Promotion\Wizard as PromotionWizard;
use App\Modules\Students\Livewire\Reports\Index as StudentsReportsIndex;
use App\Modules\Welfare\Livewire\Reports\Index as WelfareReportsIndex;
use App\Modules\Students\Livewire\Import\Index as StudentsImportIndex;
use App\Modules\Students\Livewire\Students\Index as StudentsIndex;
use App\Modules\Students\Livewire\Students\Show as StudentsShow;
use App\Modules\Welfare\Livewire\Discipline\CaseShow as DisciplineCaseShow;
use App\Modules\Welfare\Livewire\Discipline\Index as DisciplineIndex;
use App\Modules\Activities\Livewire\Index as ActivitiesIndex;
use App\Modules\Activities\Livewire\Show as ActivitiesShow;
use App\Modules\Curriculum\Livewire\Index as CurriculumIndex;
use App\Modules\Curriculum\Livewire\Show as CurriculumShow;
use App\Modules\Welfare\Livewire\Hostel\Index as HostelIndex;
use App\Modules\Welfare\Livewire\Hostel\RoomShow as HostelRoomShow;
use App\Modules\Welfare\Livewire\Insurance\Index as InsuranceIndex;
use App\Modules\Welfare\Livewire\Insurance\PolicyShow as InsurancePolicyShow;
use App\Modules\Welfare\Livewire\Medical\Index as MedicalIndex;
use App\Modules\Welfare\Livewire\Transport\Index as TransportIndex;
use App\Modules\Welfare\Livewire\Transport\VehicleShow as TransportVehicleShow;
use App\Modules\Welfare\Livewire\Visitors\Index as VisitorsIndex;
use App\Modules\Accounting\Support\ExpenseLedgerSource;
use App\Modules\Assets\Support\AssetLedgerSource;
use App\Modules\Payroll\Support\PayrollLedgerSource;
use App\Modules\Procurement\Support\ProcurementLedgerSource;
use App\Support\Ledger\LedgerSourceRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Accounting Review's ledger-source contract, docs/specs/2026-08-12-
        // accounting-finance-architecture.md 6.1. Each module's resolver
        // imports only its own models (legal under ModuleBoundaryTest);
        // Accounting depends on the registry, never on the resolvers'
        // concrete classes. Registration order is resolution priority.
        $this->app->singleton(LedgerSourceRegistry::class, function (): LedgerSourceRegistry {
            $registry = new LedgerSourceRegistry();

            $registry->register(new ExpenseLedgerSource());
            $registry->register(new ProcurementLedgerSource());
            $registry->register(new AssetLedgerSource());
            $registry->register(new PayrollLedgerSource());

            return $registry;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Phase 12 (docs/plans/phase-12-13.md 12.4): named rate limiters.
        //
        // `api` backs the throttle in the stock `api` middleware group that
        // every routes/api.php route runs through: 60 requests/min keyed by
        // the authenticated user (so two integrations holding different
        // tokens do not share a budget) and by IP before authentication (so
        // a credential-guessing loop is throttled too).
        RateLimiter::for('api', function (Request $request): Limit {
            $key = $request->user()?->getAuthIdentifier();

            return Limit::perMinute(60)->by($key !== null ? 'user:'.$key : 'ip:'.$request->ip());
        });

        // `verify` is the public document-verification budget from
        // docs/specs/10-documents.md 17.2: 10/min per IP. Defined in Phase 12
        // alongside `api` so Phase 13's /verify route only has to name it;
        // per-IP because the endpoint is anonymous by design.
        RateLimiter::for('verify', function (Request $request): Limit {
            return Limit::perMinute(10)->by('verify:'.$request->ip());
        });

        // The guardian mobile budgets (docs/specs/2026-08-11-guardian-mobile-
        // api-v1.md 2.4). `auth-mobile` guards the unauthenticated token
        // endpoint: 5/min keyed by IP AND the submitted identifier, so a
        // password spray across many accounts from one address and a
        // brute force against one account are both walled. The identifier is
        // hashed into the key - a rate-limit cache entry is not a place to
        // keep an email address in clear.
        RateLimiter::for('auth-mobile', function (Request $request): Limit {
            $identifier = mb_strtolower(trim((string) $request->input('identifier', '')));

            return Limit::perMinute(5)->by(
                'auth-mobile:'.$request->ip().':'.hash('sha256', $identifier),
            );
        });

        // `api-portal` is the authenticated mobile budget: 120/min per TOKEN,
        // not per user, so a parent signed in on a phone and a tablet does not
        // throttle themselves. Higher than `api`'s 60 because one screen
        // legitimately fans out into several reads when it opens.
        RateLimiter::for('api-portal', function (Request $request): Limit {
            $token = $request->user()?->currentAccessToken();
            $tokenId = $token?->getKey();

            $key = $tokenId !== null
                ? 'token:'.$tokenId
                : 'user:'.($request->user()?->getAuthIdentifier() ?? $request->ip());

            return Limit::perMinute(120)->by('api-portal:'.$key);
        });

        // Livewire infers a component's public name from its class name, and
        // strips a trailing ".index" segment on the assumption the class
        // itself is reachable via a sibling namespace lookup (09-ui 8.10's
        // Users\Index has no such sibling). Left to the default resolver,
        // routing straight to the class - as routes/web.php does - throws
        // ComponentNotFoundException. An explicit name sidesteps the
        // stripping logic entirely (Finder::normalizeName resolves it before
        // ever reaching the ".index" special case).
        Livewire::component('users.index', UsersIndex::class);

        // API token management (Phase 12), routed at /users/{user}/tokens.
        // Named for the same one-mechanism symmetry as every routed
        // component in this file.
        Livewire::component('users.tokens', UserTokens::class);

        // Same reasoning for the Academics screens: two of the three end in
        // ".index", and the settings screen gets an explicit name for symmetry
        // so every routed component in this module resolves the same way.
        Livewire::component('academics.settings', AcademicSettings::class);
        Livewire::component('subjects.index', SubjectsIndex::class);
        Livewire::component('classes.index', ClassGroupsIndex::class);

        // Admissions, docs/specs/07-students.md 6.2. `Wizard` does not end in
        // ".index", so the default resolver would handle it - the explicit
        // name is registered anyway for the same reason as the Academics
        // screens above: every routed component in this application resolves
        // through one mechanism, so a future rename cannot quietly change how
        // one of them is found.
        Livewire::component('admissions.wizard', AdmissionsWizard::class);
        Livewire::component('admissions.index', AdmissionsIndex::class);
        Livewire::component('identity.audit-log.index', AuditLogIndex::class);

        // People (07-students 11). `students.index` needs the explicit name
        // for the ".index"-stripping reason above; the other two routed
        // components are named for symmetry, and the panel because it is
        // mounted by TAG (<livewire:students.guardians-panel/>) from a
        // Students-module view that must never name a Guardians class -
        // tests/Architecture/ModuleBoundaryTest.php.
        Livewire::component('students.index', StudentsIndex::class);
        Livewire::component('students.show', StudentsShow::class);
        Livewire::component('guardians.index', GuardiansIndex::class);
        Livewire::component('guardians.show', GuardiansShow::class);
        Livewire::component('students.guardians-panel', StudentGuardiansPanel::class);

        // Marks entry (01-assessment 17). Named explicitly for the same reason
        // as every routed component above: one mechanism finds all of them, so
        // a future rename cannot quietly change how one is resolved.
        Livewire::component('assessment.marks-entry', MarksEntry::class);
        Livewire::component('assessment.examinations.index', ExaminationsIndex::class);
        Livewire::component('assessment.results.index', ResultsIndex::class);

        // Ledger, docs/specs/02-accounting.md, routed at /ledger/* in
        // routes/web.php. Both list screens end in ".index" (the stripping
        // reason above); Form and TrialBalance are aliased anyway for the
        // same symmetry reason as every other module here - one resolution
        // mechanism for every routed component, so a future rename cannot
        // quietly break one.
        Livewire::component('accounting.chart-of-accounts.index', ChartOfAccountsIndex::class);
        Livewire::component('accounting.journal-entries.index', JournalEntriesIndex::class);
        Livewire::component('accounting.journal-entries.form', JournalEntryForm::class);
        Livewire::component('accounting.reports.trial-balance', TrialBalanceReport::class);

        // Fees (docs/specs/04-fees.md), routed at /finance/* in routes/web.php.
        // `fees.invoices.index` needs the explicit name for the ".index"
        // stripping reason above; Cashier and Statement are aliased for the
        // same one-mechanism symmetry as every routed component here.
        Livewire::component('fees.cashier', FeesCashier::class);
        Livewire::component('fees.cashdesk.show', FeesCashDeskShow::class);
        Livewire::component('fees.invoices.index', FeesInvoicesIndex::class);
        Livewire::component('fees.statement', FeesStatement::class);

        // Phase 8 (docs/plans/phase-08.md), routed by the F5 pass 2 at
        // /timetable, /attendance*, /welfare/discipline* and
        // /students/promotion. `timetable.index`, `attendance.index` and
        // `welfare.discipline.index` need the explicit name for the
        // ".index"-stripping reason above; the rest are aliased anyway for
        // the same one-mechanism symmetry as every routed component in this
        // file.
        Livewire::component('timetable.index', TimetableIndex::class);
        Livewire::component('attendance.index', AttendanceIndex::class);
        Livewire::component('attendance.take', AttendanceTakeRegister::class);
        Livewire::component('attendance.coverage', AttendanceCoverageReport::class);
        Livewire::component('welfare.discipline.index', DisciplineIndex::class);
        Livewire::component('welfare.discipline.show', DisciplineCaseShow::class);
        Livewire::component('welfare.transport.index', TransportIndex::class);
        Livewire::component('welfare.hostel.index', HostelIndex::class);
        Livewire::component('welfare.medical.index', MedicalIndex::class);
        Livewire::component('welfare.visitors.index', VisitorsIndex::class);
        Livewire::component('welfare.insurance.index', InsuranceIndex::class);
        Livewire::component('activities.index', ActivitiesIndex::class);
        Livewire::component('activities.show', ActivitiesShow::class);

        // Curriculum and Alumni need the same explicit names Activities got:
        // Livewire's convention-based lookup derives a component name from the
        // class's path under app/Livewire, and a module class living at
        // app/Modules/<Module>/Livewire resolves to the nonsense name
        // `app.modules.<module>.livewire`, which nothing has registered - so
        // routing straight to the class 500s with ComponentNotFoundException
        // the moment the page is opened. Every module screen above is here for
        // this reason; these two were merged from branches whose screen suites
        // never got a confirming run, so the gap reached the demo.
        Livewire::component('curriculum.index', CurriculumIndex::class);
        Livewire::component('curriculum.show', CurriculumShow::class);
        Livewire::component('assets.index', AssetsIndex::class);
        Livewire::component('inventory.index', InventoryIndex::class);
        Livewire::component('library.index', LibraryIndex::class);
        Livewire::component('alumni.index', AlumniIndex::class);
        Livewire::component('alumni.show', AlumniShow::class);
        Livewire::component('hr.index', HrIndex::class);
        Livewire::component('payroll.index', PayrollIndex::class);
        Livewire::component('procurement.suppliers.index', SuppliersIndex::class);
        Livewire::component('procurement.suppliers.show', SuppliersShow::class);
        Livewire::component('procurement.requisitions.index', RequisitionsIndex::class);
        Livewire::component('procurement.purchase-orders.index', PurchaseOrdersIndex::class);
        Livewire::component('procurement.purchase-orders.edit', PurchaseOrdersEdit::class);
        Livewire::component('procurement.goods-receipts.index', GoodsReceiptsIndex::class);
        Livewire::component('procurement.supplier-invoices.index', SupplierInvoicesIndex::class);
        Livewire::component('procurement.supplier-invoices.capture', SupplierInvoicesCapture::class);
        Livewire::component('procurement.payments.index', ProcurementPaymentsIndex::class);
        Livewire::component('procurement.payments.pay', ProcurementPaymentsPay::class);
        Livewire::component('procurement.payables-dashboard', PayablesDashboard::class);
        Livewire::component('reports.hub', ReportsHub::class);
        Livewire::component('operations.backups.index', OperationsBackupsIndex::class);
        Livewire::component('reporting.bulk-prints.index', BulkPrintsIndex::class);
        Livewire::component('communication.outbox.index', CommunicationOutboxIndex::class);
        Livewire::component('communication.templates.index', CommunicationTemplatesIndex::class);
        Livewire::component('reports.academic', AcademicReportsIndex::class);
        Livewire::component('reports.financial', AccountingReportsIndex::class);
        Livewire::component('accounting.statements.index', AccountingStatementsIndex::class);
        Livewire::component('accounting.year-end.console', YearEndConsole::class);
        Livewire::component('guardians.meetings.index', GuardianMeetingsIndex::class);
        Livewire::component('guardians.pta.index', GuardianPtaIndex::class);
        Livewire::component('assessment.homework.index', AssessmentHomeworkIndex::class);
        Livewire::component('communication.messages.index', CommunicationMessagesIndex::class);
        Livewire::component('notifications.bell', NotificationsBell::class);
        Livewire::component('forms.unfinished-work', FormsUnfinishedWork::class);
        Livewire::component('operations.setup.index', OperationsSetupIndex::class);
        Livewire::component('students.import.index', StudentsImportIndex::class);
        Livewire::component('accounting.books.index', AccountingBooksIndex::class);
        Livewire::component('reporting.webhooks.index', ReportingWebhooksIndex::class);
        Livewire::component('reporting.global-search.index', ReportingGlobalSearchIndex::class);
        Livewire::component('accounting.system-documentation.index', AccountingSystemDocumentationIndex::class);
        Livewire::component('accounting.budgets.index', AccountingBudgetsIndex::class);
        Livewire::component('accounting.reconciliation.index', AccountingReconciliationIndex::class);
        Livewire::component('accounting.finance-dashboard', FinanceDashboard::class);
        Livewire::component('accounting.expenses.index', ExpensesIndex::class);
        Livewire::component('accounting.expenses.show', ExpensesShow::class);
        Livewire::component('reports.assessment', \App\Modules\Assessment\Livewire\Reports\Index::class);
        Livewire::component('reports.fees', FeesReportsIndex::class);
        Livewire::component('reports.hr', HrReportsIndex::class);
        Livewire::component('reports.procurement', ProcurementReportsIndex::class);
        Livewire::component('reports.library', LibraryReportsIndex::class);
        Livewire::component('reports.students-guardians', StudentsReportsIndex::class);
        Livewire::component('reports.welfare', WelfareReportsIndex::class);
        Livewire::component('reports.assets-inventory', AssetsReportsIndex::class);
        Livewire::component('assets.show', AssetsShow::class);
        Livewire::component('payroll.runs.show', PayrollShow::class);
        Livewire::component('procurement.purchase-orders.show', PurchaseOrdersShow::class);
        Livewire::component('procurement.supplier-invoices.show', SupplierInvoicesShow::class);
        Livewire::component('procurement.payments.show', ProcurementPaymentsShow::class);
        Livewire::component('inventory.items.show', InventoryShow::class);
        Livewire::component('transport.vehicles.show', TransportVehicleShow::class);
        Livewire::component('hostel.rooms.show', HostelRoomShow::class);
        Livewire::component('insurance.policies.show', InsurancePolicyShow::class);
        Livewire::component('library.books.show', LibraryBookShow::class);
        Livewire::component('library.members.show', LibraryMemberShow::class);
        Livewire::component('assessment.examinations.show', ExaminationsShow::class);
        Livewire::component('reports.tax', TaxReportsIndex::class);
        // Without this the route 500s: Livewire's implicit name for a class
        // ending in `Index` collapses to its parent namespace
        // (`...tax.livewire.declarations`), which resolves to nothing. Only
        // an explicit alias fixes it - and Livewire::test() never exercises
        // that path, which is how this shipped looking verified.
        Livewire::component('tax.declarations.index', TaxDeclarationsIndex::class);
        Livewire::component('schoolprofile.index', SettingsIndex::class);
        Livewire::component('students.promotion', PromotionWizard::class);
    }
}
