<?php

use App\Modules\Identity\Livewire\Auth\Login;
use App\Modules\Operations\Http\HealthController;
use App\Modules\Operations\Livewire\Dashboard;
use Illuminate\Support\Facades\Route;

/*
 * The bare domain is not a page of its own: an operator lands on their
 * dashboard, and a visitor without a session is bounced to login by the
 * dashboard's own auth middleware. (Until now this served Laravel's stock
 * welcome view - the one page of the framework skeleton nobody ever
 * replaced, so http://opeschool.test greeted a school with Laracasts links.)
 */
Route::redirect('/', '/dashboard');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', Login::class)->name('login');
});

Route::post('/logout', function () {
    auth()->logout();
    session()->invalidate();
    session()->regenerateToken();

    return redirect()->route('login');
})->middleware('auth')->name('logout');

Route::middleware('auth')->group(function (): void {
    Route::get('/dashboard', Dashboard::class)->name('dashboard');

    /*
     * The operator's UI language, not the school's document language - see
     * Identity\Http\Middleware\SetLocale. Only `en` and `fr` are accepted; a
     * rejected value comes back as a validation error rather than silently
     * leaving the interface as it was.
     */
    Route::post('/locale', function (\Illuminate\Http\Request $request) {
        $validated = $request->validate(['locale' => 'required|in:en,fr']);
        session(['locale' => $validated['locale']]);

        return back();
    })->name('locale.set');

    /*
     * User Management, docs/specs/09-ui.md section 8.10. The gate is real from
     * the first day on purpose: the sidebar hides this link from a user
     * without `user.view`, and hiding a link is presentation, never a control
     * (00-core 6.2). The route has to refuse on its own.
     */
    Route::get('/users', \App\Modules\Identity\Livewire\Users\Index::class)
        ->middleware('can:user.view')->name('users.index');

    Route::get('/users/create', \App\Modules\Identity\Livewire\Users\Form::class)
        ->middleware('can:user.manage')->name('users.create');

    /*
     * API token management, docs/plans/phase-12-13.md 12.4. Gated on the
     * dedicated `api.manage_tokens` permission (Phase 12's ApiTokenManage
     * enum case) rather than the broader `user.manage`: issuing a credential
     * that works from outside the building is a heavier right than editing a
     * user record, so it is grantable - and revocable - on its own.
     */
    Route::get('/users/{user}/tokens', \App\Modules\Identity\Livewire\Users\Tokens::class)
        ->middleware('can:api.manage_tokens')->whereNumber('user')->name('users.tokens');

    /*
     * Academic core, docs/specs/00-core.md 9.1. Same principle as /users: the
     * sidebar hides these from a user without `academics.view`, but hiding is
     * presentation - the route refuses on its own. Settings is gated harder
     * (`academics.manage`) because it shapes the structure the rest read.
     */
    Route::get('/academics/settings', \App\Modules\Academics\Livewire\Settings\AcademicSettings::class)
        ->middleware('can:academics.manage')->name('academics.settings');

    Route::get('/classes', \App\Modules\Academics\Livewire\ClassGroups\Index::class)
        ->middleware('can:academics.view')->name('classes.index');

    Route::get('/subjects', \App\Modules\Academics\Livewire\Subjects\Index::class)
        ->middleware('can:academics.view')->name('subjects.index');

    /*
     * Timetable, docs/specs/09-ui.md §8.6 (Phase 8 F1, wired by the F5 pass
     * 2). One screen, three tabs (Class/Teacher/Room) plus a read-only Exam
     * tab - all gated on the READ permission; the write actions (assign/
     * remove a slot) are gated harder (`timetable.manage`) inside the
     * component and re-authorized in AssignTimetableSlot/RemoveTimetableSlot,
     * the same screen-vs-write split every module above uses.
     */
    Route::get('/timetable', \App\Modules\Academics\Livewire\Timetable\Index::class)
        ->middleware('can:timetable.view')->name('timetable.index');

    /*
     * Attendance, docs/specs/07-students.md §9 / 09-ui.md §8.7 (Phase 8 F2,
     * wired by the F5 pass 2). The management screen and the coverage report
     * are both READ surfaces (`attendance.view`); taking a register is gated
     * harder (`attendance.take`) because it is the write path - the
     * component and OpenAttendanceRegister/SubmitAttendanceRegister
     * re-authorize it, so the route's `can:` is the outer gate only.
     */
    Route::get('/attendance', \App\Modules\Attendance\Livewire\Index::class)
        ->middleware('can:attendance.view')->name('attendance.index');

    Route::get('/attendance/take', \App\Modules\Attendance\Livewire\TakeRegister::class)
        ->middleware('can:attendance.take')->name('attendance.take');

    Route::get('/attendance/coverage', \App\Modules\Attendance\Livewire\CoverageReport::class)
        ->middleware('can:attendance.view')->name('attendance.coverage');

    /*
     * People, docs/specs/07-students.md. Same principle again: the sidebar
     * hides what the operator cannot reach, but hiding is presentation - each
     * route refuses on its own. Every `can:` here matches the permission its
     * nav item carries in Identity\Support\Navigation, which is that file's
     * documented contract.
     *
     * A guardian record is read by whoever may read the student it belongs to,
     * so `guardians.show` is gated on `students.view`; `guardians.manage`
     * gates writing, which happens inside the student screens.
     */
    Route::get('/students', \App\Modules\Students\Livewire\Students\Index::class)
        ->middleware('can:students.view')->name('students.index');

    /*
     * Promotion, docs/specs/07-students.md §10 (Phase 8 F4, wired by the F5
     * pass 2). One route for the whole evaluate -> review/override -> apply
     * wizard - the `#[Url] run` query param resumes an in-progress run.
     * Gated on `promotion.evaluate` as the outer/read gate; the apply step is
     * gated harder (`promotion.apply`) inside the component and
     * re-authorized in ApplyPromotionRun, the same screen-vs-write split
     * every module above uses.
     *
     * MUST be registered before `/students/{student}` below: that route
     * carries no numeric constraint, so "promotion" would otherwise match it
     * as a student id and this route would never be reached.
     */
    Route::get('/students/promotion', \App\Modules\Students\Livewire\Promotion\Wizard::class)
        ->middleware('can:promotion.evaluate')->name('students.promotion');

    /*
     * Data import (00-core §15 Phase 2). Three phases - stage, validate,
     * commit - so a school sees which rows would fail before any record
     * exists. Gated on students.manage: this creates people.
     *
     * MUST precede /students/{student}: that route carries no numeric
     * constraint, so "import" would match it as a student id and this
     * route would never be reached - the same ordering trap documented
     * for /students/promotion above.
     */
    /*
     * Go-live readiness (00-core §16 blocking gates). Read-only by
     * design: every row is evaluated against live data, so nothing can
     * be ticked to turn a red row green - a school gets green by
     * configuring the thing.
     */
    Route::get('/setup', \App\Modules\Operations\Livewire\Setup\Index::class)
        ->middleware('can:setting.view')->name('operations.setup');

    Route::get('/students/import', \App\Modules\Students\Livewire\Import\Index::class)
        ->middleware('can:students.manage')->name('students.import');

    Route::get('/students/{student}', \App\Modules\Students\Livewire\Students\Show::class)
        ->middleware('can:students.view')->name('students.show');

    /*
     * Guardians directory - built and module-tested but never routed/wired
     * into the sidebar; this closes that gap the same way the Welfare block
     * further down does. MUST be registered before `/guardians/{guardian}`
     * below, same ordering reason as `/students/promotion` above.
     */
    Route::get('/guardians', \App\Modules\Guardians\Livewire\Guardians\Index::class)
        ->middleware('can:guardians.manage')->name('guardians.index');

    Route::get('/guardians/{guardian}', \App\Modules\Guardians\Livewire\Guardians\Show::class)
        ->middleware('can:students.view')->name('guardians.show');

    /*
     * 07-students §6.2: the registrar's landing page is the application
     * QUEUE, not the intake wizard. /admissions used to drop straight into
     * the wizard, so a submitted application could be created but never
     * triaged. The wizard keeps its own URL and is now reached from the
     * queue (or directly, for a fresh intake).
     */
    Route::get('/admissions', \App\Modules\Admissions\Livewire\Index::class)
        ->middleware('can:admissions.manage')->name('admissions.index');

    Route::get('/admissions/wizard', \App\Modules\Admissions\Livewire\Wizard::class)
        ->middleware('can:admissions.manage')->name('admissions.wizard');

    /*
     * 09-ui §8.11: the audit log has always been written and hash-chained;
     * it simply had no viewer. "An un-viewable audit log satisfies no
     * auditor." Chain verification runs the existing VerifyAuditChain.
     */
    Route::get('/audit-log', \App\Modules\Identity\Livewire\AuditLog\Index::class)
        ->middleware('can:audit.view')->name('audit.index');

    /*
     * 08-operations §3.8: the backup engine and its drills have always
     * existed as artisan commands only, so restore was CLI-only and the
     * dashboard could nag about a missing backup with no button to press.
     * Restore itself stays gated on backup.restore (withheld even from
     * Administrator by Role::defaultPermissions) and is surfaced, not
     * automated.
     */
    Route::get('/operations/backups', \App\Modules\Operations\Livewire\Backups\Index::class)
        ->middleware('can:backup.run')->name('operations.backups');

    /*
     * 10-documents §18: bulk print jobs. The table shipped in Phase 13 with
     * no model, Action or screen behind it.
     */
    Route::get('/documents/bulk-prints', \App\Modules\Reporting\Livewire\BulkPrints\Index::class)
        ->middleware('can:documents.bulk_print')->name('documents.bulk-prints');

    /*
     * Communication outbox (08-operations §11.1). The tables shipped in
     * Phase 12 with no code behind them, so every "degrades to a queued
     * outbox" promise in the specs degraded to nothing. The SMS gateway
     * itself stays deferred - this is the outbox and its log driver.
     */
    Route::get('/communication/outbox', \App\Modules\Communication\Livewire\Outbox\Index::class)
        ->middleware('can:communication.view')->name('communication.outbox');

    Route::get('/communication/templates', \App\Modules\Communication\Livewire\Templates\Index::class)
        ->middleware('can:communication.view')->name('communication.templates');

    /*
     * Marks entry, docs/specs/01-assessment.md 17. The single highest-traffic
     * academic screen.
     *
     * `can:marks.enter` is the OUTER gate only. 7.5 scopes entry to the
     * allocations the actor teaches or has been delegated, and the component
     * re-checks that through Mark::mayEnter() on mount and on every write -
     * T22 treats reaching an unassigned allocation as a failure even for a
     * user who holds the permission.
     */
    /*
     * Examinations (scheduling) and Results (computed results/statistics) -
     * built and module-tested but never routed/wired into the sidebar.
     * Registered before /marks so ordering matches the rest of this file's
     * convention of specific-before-parameterised, though neither collides
     * with /marks in practice.
     */
    Route::get('/examinations', \App\Modules\Assessment\Livewire\Examinations\Index::class)
        ->middleware('can:assessment.configure')->name('assessment.examinations.index');

    Route::get('/examinations/{exam}', \App\Modules\Assessment\Livewire\Examinations\Show::class)
        ->middleware('can:assessment.configure')->whereNumber('exam')->name('assessment.examinations.show');

    Route::get('/results', \App\Modules\Assessment\Livewire\Results\Index::class)
        ->middleware('can:academics.view')->name('assessment.results.index');

    Route::get('/marks', \App\Modules\Assessment\Livewire\Marks\Entry::class)
        ->middleware('can:marks.enter')->name('marks.entry');

    /*
     * Ledger, docs/specs/02-accounting.md. Same principle again: the sidebar
     * hides what the operator cannot reach, but hiding is presentation - each
     * route refuses on its own. Journal-entry creation is gated harder
     * (`ledger.post`) because it writes to the books; the rest is read-only.
     */
    Route::get('/ledger/chart-of-accounts', \App\Modules\Accounting\Livewire\ChartOfAccounts\Index::class)
        ->middleware('can:ledger.view')->name('ledger.chart-of-accounts');

    Route::get('/ledger/journal-entries', \App\Modules\Accounting\Livewire\JournalEntries\Index::class)
        ->middleware('can:ledger.view')->name('ledger.journal-entries.index');

    Route::get('/ledger/journal-entries/create', \App\Modules\Accounting\Livewire\JournalEntries\Form::class)
        ->middleware('can:ledger.post')->name('ledger.journal-entries.create');

    Route::get('/ledger/trial-balance', \App\Modules\Accounting\Livewire\Reports\TrialBalance::class)
        ->middleware('can:ledger.view')->name('ledger.trial-balance');

    /*
     * Fees, docs/specs/04-fees.md. All three screens are REACHABLE under
     * `fee.view` - the sidebar's finance item is gated on fee.view, and the
     * Navigation contract is that nav and route agree by construction, so a
     * Principal who may read fee data can open the fee screens. What is
     * gated harder is the ACT of collecting: the Collect button requires
     * `fee.collect` (checked in the component AND re-authorized inside F3's
     * RecordPayment), the same screen-vs-write split the ledger uses for
     * journal-entry creation.
     *
     * /finance itself redirects to the invoices list - the sidebar item
     * lands there, and the bookmark made while /finance was a placeholder
     * still resolves.
     */
    Route::redirect('/finance', '/finance/invoices');

    Route::get('/finance/invoices', \App\Modules\Fees\Livewire\Invoices\Index::class)
        ->middleware('can:fee.view')->name('fees.invoices.index');

    Route::get('/finance/cashier', \App\Modules\Fees\Livewire\Cashier::class)
        ->middleware('can:fee.view')->name('fees.cashier');

    /*
     * Cash-desk close-out sheet (04-fees §11.7): opening float, the session's
     * own collections, expected vs counted, and the variance with its written
     * reason. Read-only - opening and closing happen on the Cashier screen.
     */
    Route::get('/finance/cash-desk/{session}', \App\Modules\Fees\Livewire\CashDesk\Show::class)
        ->middleware('can:fee.view')->whereNumber('session')->name('fees.cashdesk.show');

    Route::get('/finance/statement/{student}', \App\Modules\Fees\Livewire\Statement::class)
        ->middleware('can:fee.view')->whereNumber('student')->name('fees.students.statement');

    /*
     * Money-document Print buttons, docs/specs/10-documents.md §10
     * (phase-12-13 D3). Gated the SAME as the screen they print from
     * (`fee.view`) - the harder gate (documents.print, and
     * documents.reprint[_financial] on a second render of the same
     * payment/invoice) is re-checked inside RenderDocument itself, the
     * same screen-vs-act split every other module in this file uses.
     */
    Route::get('/finance/payments/{payment}/receipt', \App\Modules\Fees\Http\Controllers\PrintReceiptController::class)
        ->middleware('can:fee.view')->whereNumber('payment')->name('fees.payments.receipt');

    Route::get('/finance/invoices/{invoice}/print', \App\Modules\Fees\Http\Controllers\PrintInvoiceController::class)
        ->middleware('can:fee.view')->whereNumber('invoice')->name('fees.invoices.print');

    Route::get('/finance/statement/{student}/print', \App\Modules\Fees\Http\Controllers\PrintStatementController::class)
        ->middleware('can:fee.view')->whereNumber('student')->name('fees.students.statement.print');

    /*
     * Procurement, docs/specs/03-tax-procurement.md (Phase 5, wired by the
     * F5 pass). Same principle as every block above: the sidebar hides what
     * the operator cannot reach, but hiding is presentation - each route
     * refuses on its own, and every `can:` matches the permission its nav
     * item carries in Identity\Support\Navigation.
     *
     * The READ gate (procurement.view / invoice_view / payment_record)
     * opens the screens; the ACTS - approving a requisition or order,
     * approving/voiding a payment, posting an invoice - are gated harder
     * inside the components and re-authorized in the Actions, the same
     * screen-vs-write split the ledger and fees use.
     *
     * /procurement itself lands on the supplier register (the sidebar
     * item's target); the payables dashboard has its own address.
     */
    Route::redirect('/procurement', '/procurement/suppliers');

    Route::get('/procurement/suppliers', \App\Modules\Procurement\Livewire\Suppliers\Index::class)
        ->middleware('can:procurement.view')->name('procurement.suppliers.index');

    Route::get('/procurement/suppliers/{supplier}', \App\Modules\Procurement\Livewire\Suppliers\Show::class)
        ->middleware('can:procurement.view')->whereNumber('supplier')->name('procurement.suppliers.show');

    Route::get('/procurement/requisitions', \App\Modules\Procurement\Livewire\Requisitions\Index::class)
        ->middleware('can:procurement.view')->name('procurement.requisitions.index');

    Route::get('/procurement/orders', \App\Modules\Procurement\Livewire\PurchaseOrders\Index::class)
        ->middleware('can:procurement.view')->name('procurement.orders.index');

    Route::get('/procurement/orders/capture', \App\Modules\Procurement\Livewire\PurchaseOrders\Edit::class)
        ->middleware('can:procurement.order_manage')->name('procurement.orders.capture');

    Route::get('/procurement/orders/{order}', \App\Modules\Procurement\Livewire\PurchaseOrders\Show::class)
        ->middleware('can:procurement.view')->whereNumber('order')->name('procurement.orders.show');

    Route::get('/procurement/receipts', \App\Modules\Procurement\Livewire\GoodsReceipts\Index::class)
        ->middleware('can:procurement.view')->name('procurement.receipts.index');

    Route::get('/procurement/invoices', \App\Modules\Procurement\Livewire\SupplierInvoices\Index::class)
        ->middleware('can:procurement.invoice_view')->name('procurement.invoices.index');

    Route::get('/procurement/invoices/capture', \App\Modules\Procurement\Livewire\SupplierInvoices\Capture::class)
        ->middleware('can:procurement.invoice_create')->name('procurement.invoices.capture');

    Route::get('/procurement/invoices/{invoice}', \App\Modules\Procurement\Livewire\SupplierInvoices\Show::class)
        ->middleware('can:procurement.invoice_view')->whereNumber('invoice')->name('procurement.invoices.show');

    Route::get('/procurement/payments', \App\Modules\Procurement\Livewire\Payments\Index::class)
        ->middleware('can:procurement.payment_record')->name('procurement.payments.index');

    Route::get('/procurement/payments/pay', \App\Modules\Procurement\Livewire\Payments\Pay::class)
        ->middleware('can:procurement.payment_record')->name('procurement.payments.pay');

    Route::get('/procurement/payments/{payment}', \App\Modules\Procurement\Livewire\Payments\Show::class)
        ->middleware('can:procurement.payment_record')->whereNumber('payment')->name('procurement.payments.show');

    Route::get('/procurement/payables', \App\Modules\Procurement\Livewire\PayablesDashboard::class)
        ->middleware('can:procurement.view')->name('procurement.payables');

    /*
     * Payment Voucher print button, docs/plans/phase-12-13.md D3 - same gate
     * as the Payments screens above.
     */
    Route::get('/procurement/payments/{payment}/voucher', \App\Modules\Procurement\Http\Controllers\PrintPaymentVoucherController::class)
        ->middleware('can:procurement.payment_record')->whereNumber('payment')->name('procurement.payments.voucher');

    /*
     * Tax, docs/specs/03-tax-procurement.md §7/§10. The dashboard reads
     * under tax.view; the declarations register requires tax.declare (its
     * content IS the declaration workflow); the configuration cockpit and
     * fiscal identity live under /settings behind ledger.configure, the
     * same right that shapes the ledger those settings feed.
     */
    Route::get('/tax', \App\Modules\Tax\Livewire\TaxDashboard::class)
        ->middleware('can:tax.view')->name('tax.dashboard');

    Route::get('/tax/declarations', \App\Modules\Tax\Livewire\Declarations\Index::class)
        ->middleware('can:tax.declare')->name('tax.declarations.index');

    Route::get('/tax/declarations/{declaration}', \App\Modules\Tax\Livewire\Declarations\Show::class)
        ->middleware('can:tax.declare')->whereNumber('declaration')->name('tax.declarations.show');

    Route::get('/settings/fiscal-identity', \App\Modules\Tax\Livewire\FiscalIdentity::class)
        ->middleware('can:ledger.configure')->name('tax.fiscal-identity');

    Route::get('/settings/tax', \App\Modules\Tax\Livewire\TaxConfiguration::class)
        ->middleware('can:ledger.configure')->name('tax.settings');

    /*
     * Settings (SchoolProfile's generic engine-configuration store) - built
     * and module-tested but never routed/wired into the sidebar. Read-only
     * for this pass (WriteSetting's locking/validation/audit contract needs
     * its own dedicated screen, not squeezed in here). Gated on setting.view,
     * matching Navigation::items()'s `settings` entry.
     */
    Route::get('/settings', \App\Modules\SchoolProfile\Livewire\Index::class)
        ->middleware('can:setting.view')->name('settings.index');

    /*
     * Withholding Attestation print button, 03-tax-procurement §6.6 /
     * 10-documents §15's WHT-CERT (phase-12-13 D3) - gated tax.view, the
     * same read right the Tax dashboard uses.
     */
    Route::get('/tax/withholding-attestations/{attestation}/print', \App\Modules\Tax\Http\Controllers\PrintWithholdingAttestationController::class)
        ->middleware('can:tax.view')->whereNumber('attestation')->name('tax.withholding-attestations.print');

    /*
     * Operations (Phase 7, docs/specs/08-operations.md §6): the year-rollover
     * wizard. One permission gates the whole run - see
     * Identity\Domain\Permission::RolloverRun - and every step Action
     * re-authorizes it, so the route's `can:` is the outer gate only, same
     * screen-vs-write split as everywhere above.
     */
    Route::get('/operations/rollover', \App\Modules\Operations\Livewire\RolloverWizard::class)
        ->middleware('can:rollover.run')->name('operations.rollover');

    /*
     * The licence panel (08-operations §4). Behind licence.manage - which the
     * plain Administrator deliberately does NOT hold (AuthorizationMatrixTest)
     * - and NOT under the /settings placeholder route: /settings itself still
     * serves the scheduled-module page, while this concrete settings screen
     * has its own address, as /settings/tax and /settings/fiscal-identity do.
     */
    Route::get('/settings/licence', \App\Modules\Operations\Livewire\LicencePanel::class)
        ->middleware('can:licence.manage')->name('settings.licence');

    /*
     * Document verification, docs/specs/10-documents.md §17.2: the in-app
     * (LAN) screen - paste or scan an OPES1 token, get the four-state answer.
     * Auth-only, no permission: the page holds no student data by
     * construction, and verifying a presented certificate is front-desk work.
     * noindex via header per §17.2.
     */
    Route::get('/documents/verify', \App\Modules\Reporting\Livewire\Verify::class)
        ->middleware(\App\Modules\Reporting\Http\MarkNoIndex::class)
        ->name('documents.verify');

    /*
     * Discipline, docs/specs/07-students.md / 09-ui.md §2 (Phase 8 F3, wired
     * by the F5 pass 2). Deliberately NOT in the sidebar - 09-ui §2 places it
     * "reached from within" (the student profile's Discipline tab and the
     * Welfare area), so these routes exist without a Navigation item.
     * Read under `discipline.view`; opening a case, applying a sanction and
     * resolving are gated harder (`discipline.manage`) inside the component
     * and re-authorized in the Actions, the same screen-vs-write split every
     * module above uses.
     */
    Route::get('/welfare/discipline', \App\Modules\Welfare\Livewire\Discipline\Index::class)
        ->middleware('can:discipline.view')->name('welfare.discipline.index');

    Route::get('/welfare/discipline/{case}', \App\Modules\Welfare\Livewire\Discipline\CaseShow::class)
        ->middleware('can:discipline.view')->whereNumber('case')->name('welfare.discipline.show');

    /*
     * Welfare (Phase 10): transport, hostel, medical, visitors, insurance.
     * Screens were built in the overnight session but never routed/wired
     * into the sidebar - this pass closes that gap. Gated on each module's
     * `.view` permission, matching Navigation::items() below, per this
     * file's nav-and-route-agree-by-construction contract.
     */
    Route::get('/transport', \App\Modules\Welfare\Livewire\Transport\Index::class)
        ->middleware('can:transport.view')->name('welfare.transport.index');

    Route::get('/transport/vehicles/{vehicle}', \App\Modules\Welfare\Livewire\Transport\VehicleShow::class)
        ->middleware('can:transport.view')->whereNumber('vehicle')->name('transport.vehicles.show');

    Route::get('/hostel', \App\Modules\Welfare\Livewire\Hostel\Index::class)
        ->middleware('can:hostel.view')->name('welfare.hostel.index');

    Route::get('/hostel/rooms/{room}', \App\Modules\Welfare\Livewire\Hostel\RoomShow::class)
        ->middleware('can:hostel.view')->whereNumber('room')->name('hostel.rooms.show');

    Route::get('/medical', \App\Modules\Welfare\Livewire\Medical\Index::class)
        ->middleware('can:medical.view')->name('welfare.medical.index');

    Route::get('/visitors', \App\Modules\Welfare\Livewire\Visitors\Index::class)
        ->middleware('can:visitor.manage')->name('welfare.visitors.index');

    Route::get('/insurance', \App\Modules\Welfare\Livewire\Insurance\Index::class)
        ->middleware('can:insurance.view')->name('welfare.insurance.index');

    Route::get('/welfare/insurance/policies/{policy}', \App\Modules\Welfare\Livewire\Insurance\PolicyShow::class)
        ->middleware('can:insurance.view')->whereNumber('policy')->name('insurance.policies.show');

    /*
     * Phase 9 (Assets/Inventory/Library) and Phase 11 (HR/Payroll): screens
     * built and module-tested overnight but never routed/wired into the
     * sidebar - this pass closes that gap, same as the Welfare block above.
     * Gated on each module's `.view` permission, matching Navigation::items().
     */
    Route::get('/assets', \App\Modules\Assets\Livewire\Index::class)
        ->middleware('can:asset.view')->name('assets.index');

    Route::get('/assets/{asset}', \App\Modules\Assets\Livewire\Show::class)
        ->middleware('can:asset.view')->whereNumber('asset')->name('assets.show');

    Route::get('/inventory', \App\Modules\Inventory\Livewire\Index::class)
        ->middleware('can:inventory.view')->name('inventory.index');

    Route::get('/inventory/items/{item}', \App\Modules\Inventory\Livewire\Show::class)
        ->middleware('can:inventory.view')->whereNumber('item')->name('inventory.items.show');

    Route::get('/library', \App\Modules\Library\Livewire\Index::class)
        ->middleware('can:library.view')->name('library.index');

    Route::get('/library/books/{book}', \App\Modules\Library\Livewire\BookShow::class)
        ->middleware('can:library.view')->whereNumber('book')->name('library.books.show');

    Route::get('/library/members/{member}', \App\Modules\Library\Livewire\MemberShow::class)
        ->middleware('can:library.view')->whereNumber('member')->name('library.members.show');

    Route::get('/staff', \App\Modules\HR\Livewire\Index::class)
        ->middleware('can:staff.view')->name('hr.index');

    Route::get('/payroll', \App\Modules\Payroll\Livewire\Index::class)
        ->middleware('can:payroll.view')->name('payroll.index');

    Route::get('/payroll/runs/{run}', \App\Modules\Payroll\Livewire\Show::class)
        ->middleware('can:payroll.view')->whereNumber('run')->name('payroll.runs.show');

    /*
     * Reports hub (2026-08 build): the directory screen. Individual report
     * cluster screens (reports.academic, reports.assessment, ...) register
     * their own routes below/nearby as they ship; the hub lists only the
     * ones that exist (Route::has() guard in Hub::categories()).
     */
    Route::get('/reports', \App\Modules\Reporting\Livewire\Reports\Hub::class)
        ->middleware('can:reports.view')->name('reports.hub');

    Route::get('/reports/academic', \App\Modules\Academics\Livewire\Reports\Index::class)
        ->middleware('can:reports.view')->name('reports.academic');

    Route::get('/reports/financial', \App\Modules\Accounting\Livewire\Reports\Index::class)
        ->middleware('can:ledger.view')->name('reports.financial');

    /*
     * Finance Dashboard (02-accounting §21.3) - the accountant's overview:
     * KPIs with period-over-period deltas, income/expense charts, collection
     * donut, top outstanding, and the treasury position split by float
     * (cash / bank / MTN / Orange) rather than lumped together.
     */
    Route::get('/finance/dashboard', \App\Modules\Accounting\Livewire\FinanceDashboard::class)
        ->middleware('can:ledger.view')->name('finance.dashboard');

    /*
     * The OHADA/SYSCOHADA statements themselves (02-accounting §14.2/§17.7):
     * Bilan, Compte de resultat, Tableau des flux, plus prior-period
     * comparatives. Separate from /reports/financial, which carries the
     * working reports (trial balance, general ledger, journal register).
     */
    Route::get('/reports/statements', \App\Modules\Accounting\Livewire\Statements\Index::class)
        ->middleware('can:ledger.view')->name('accounting.statements');

    /*
     * Year-end console (02-accounting §17/§18): the checklist, the closing
     * entry, result appropriation and the a-nouveaux carry-forward. Without
     * this the ledger could never enter a second fiscal year.
     */
    Route::get('/accounting/year-end', \App\Modules\Accounting\Livewire\YearEnd\Console::class)
        ->middleware('can:ledger.view')->name('accounting.year-end');

    /*
     * The four AUDCIF Art. 19 books (02-accounting §14). Legal registers, not
     * reports: each generation is hashed and immutable, and a correction
     * produces a new book that supersedes its predecessor.
     */
    Route::get('/accounting/books', \App\Modules\Accounting\Livewire\Books\Index::class)
        ->middleware('can:ledger.view')->name('accounting.books');

    /*
     * Bank / mobile-money reconciliation (02-accounting §13). Each float
     * reconciles against its own operator statement - which is the whole
     * point of having split MTN 5521 from Orange 5522 in the first place.
     */
    Route::get('/accounting/reconciliation', \App\Modules\Accounting\Livewire\Reconciliation\Index::class)
        ->middleware('can:ledger.view')->name('accounting.reconciliation');

    /*
     * Budget and budget-vs-actual (02-accounting §16). Also what finally
     * gives chart_of_accounts.budget_control a reader - the column has
     * shipped seeded since Phase 4 with nothing consuming it.
     */
    Route::get('/accounting/budgets', \App\Modules\Accounting\Livewire\Budgets\Index::class)
        ->middleware('can:ledger.view')->name('accounting.budgets');

    /*
     * Expense capture (02-accounting §21.3) - the petty, unregistered,
     * cash-and-receipt spend that never goes through a supplier invoice.
     * Recording and approving are DIFFERENT rights on purpose: the
     * maker-checker split is the control, so they are gated separately.
     */
    Route::get('/accounting/expenses', \App\Modules\Accounting\Livewire\Expenses\Index::class)
        ->middleware('can:ledger.view')->name('accounting.expenses.index');

    Route::get('/accounting/expenses/{expense}', \App\Modules\Accounting\Livewire\Expenses\Show::class)
        ->middleware('can:ledger.view')->whereNumber('expense')->name('accounting.expenses.show');

    /*
     * Report card and payslip, rendered through the shared RenderDocument
     * pipeline and returned INLINE so the operator previews before printing
     * (the same behaviour the fee money-documents already have). The harder
     * documents.print / documents.reprint gates are enforced inside
     * RenderDocument itself, so the route carries only the read gate.
     */
    Route::get('/assessment/report-cards/{enrollment}/{period}/print', \App\Modules\Assessment\Http\Controllers\PrintReportCardController::class)
        ->middleware('can:academics.view')->whereNumber('enrollment')->whereNumber('period')
        ->name('assessment.report-cards.print');

    Route::get('/payroll/payslips/{payrollItem}/print', \App\Modules\Payroll\Http\Controllers\PrintPayslipController::class)
        ->middleware('can:payroll.view')->whereNumber('payrollItem')
        ->name('payroll.payslips.print');

    Route::get('/reports/assessment', \App\Modules\Assessment\Livewire\Reports\Index::class)
        ->middleware('can:reports.view')->name('reports.assessment');

    Route::get('/reports/fees', \App\Modules\Fees\Livewire\Reports\Index::class)
        ->middleware('can:reports.view')->name('reports.fees');

    Route::get('/reports/hr', \App\Modules\HR\Livewire\Reports\Index::class)
        ->middleware('can:reports.view')->name('reports.hr');

    Route::get('/reports/procurement', \App\Modules\Procurement\Livewire\Reports\Index::class)
        ->middleware('can:reports.view')->name('reports.procurement');

    Route::get('/reports/library', \App\Modules\Library\Livewire\Reports\Index::class)
        ->middleware('can:reports.view')->name('reports.library');

    Route::get('/reports/students-guardians', \App\Modules\Students\Livewire\Reports\Index::class)
        ->middleware('can:reports.view')->name('reports.students-guardians');

    Route::get('/reports/welfare', \App\Modules\Welfare\Livewire\Reports\Index::class)
        ->middleware('can:reports.view')->name('reports.welfare');

    Route::get('/reports/assets-inventory', \App\Modules\Assets\Livewire\Reports\Index::class)
        ->middleware('can:reports.view')->name('reports.assets-inventory');

    Route::get('/reports/tax', \App\Modules\Tax\Livewire\Reports\Index::class)
        ->middleware('can:reports.view')->name('reports.tax');

    /*
     * Scheduled modules. Every sidebar item is a real link: modules not yet
     * built serve an in-shell placeholder page at the SAME URL the real
     * module will later occupy, so a bookmark made today still works the day
     * the module ships. Generated from Navigation::placeholderRoutes() so a
     * new placeholder key gets its route by construction and the two files
     * cannot drift apart. Auth only - the page contains nothing but
     * translation strings, so there is no data to gate.
     */
    foreach (\App\Modules\Identity\Support\Navigation::placeholderRoutes() as $navKey => $path) {
        Route::get($path, fn () => view('shell.module-placeholder', ['moduleKey' => $navKey]))
            ->name('placeholder.'.$navKey);
    }
});

/*
 * Guardian portal (docs/plans/phase-12-13.md 12.2). Its OWN middleware
 * stack - `auth` then `guardian.portal` (EnsureGuardianPortal) - deliberately
 * separate from the staff `auth` group above: this shell shares no sidebar,
 * no staff permission gate, and no route with it. Every route name here is
 * the allow-list `GuardianDenyByDefaultRouteEnumerationTest` checks against
 * a 07-students.md 7.5 row number; adding a route here without a matching
 * capability check inside its component is exactly what that test exists to
 * catch.
 */
Route::prefix('portal')->middleware(['auth', 'guardian.portal'])->group(function (): void {
    Route::get('/', \App\Modules\Guardians\Livewire\Portal\Dashboard::class)->name('portal.dashboard');

    Route::get('/children/{student}/results', \App\Modules\Guardians\Livewire\Portal\Results::class)
        ->whereNumber('student')->name('portal.children.results');
    Route::get('/children/{student}/fees', \App\Modules\Guardians\Livewire\Portal\Fees::class)
        ->whereNumber('student')->name('portal.children.fees');
    Route::get('/children/{student}/profile', \App\Modules\Guardians\Livewire\Portal\ChildProfile::class)
        ->whereNumber('student')->name('portal.children.profile');
    Route::get('/children/{student}/discipline', \App\Modules\Guardians\Livewire\Portal\Discipline::class)
        ->whereNumber('student')->name('portal.children.discipline');
    Route::get('/children/{student}/documents', \App\Modules\Guardians\Livewire\Portal\Documents::class)
        ->whereNumber('student')->name('portal.children.documents');
});

/*
 * Staff portal shell (docs/plans/phase-12-13.md 12.3) - the `staff_portal`
 * role's own door, `staff.portal` (EnsureStaffPortal), equally separate from
 * the staff admin shell above: holding an admin role does not open this
 * screen, and activating a staff portal account does not open the admin
 * shell (Identity\Domain\Role: the two scopes are evaluated independently).
 */
Route::prefix('portal')->middleware(['auth', 'staff.portal'])->group(function (): void {
    Route::get('/staff', \App\Modules\HR\Livewire\Portal\Show::class)->name('portal.staff');
});

/*
 * Replaces Laravel's stock health route (see bootstrap/app.php). Left
 * unauthenticated on purpose so a monitor can poll it without holding a
 * credential - which is also why HealthController redacts absolute paths out of
 * every string before it answers.
 */
Route::get('/up', HealthController::class)->name('health');
