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

    Route::get('/students/{student}', \App\Modules\Students\Livewire\Students\Show::class)
        ->middleware('can:students.view')->name('students.show');

    Route::get('/guardians/{guardian}', \App\Modules\Guardians\Livewire\Guardians\Show::class)
        ->middleware('can:students.view')->name('guardians.show');

    Route::get('/admissions', \App\Modules\Admissions\Livewire\Wizard::class)
        ->middleware('can:admissions.manage')->name('admissions.index');

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

    Route::get('/finance/statement/{student}', \App\Modules\Fees\Livewire\Statement::class)
        ->middleware('can:fee.view')->whereNumber('student')->name('fees.students.statement');

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

    Route::get('/procurement/receipts', \App\Modules\Procurement\Livewire\GoodsReceipts\Index::class)
        ->middleware('can:procurement.view')->name('procurement.receipts.index');

    Route::get('/procurement/invoices', \App\Modules\Procurement\Livewire\SupplierInvoices\Index::class)
        ->middleware('can:procurement.invoice_view')->name('procurement.invoices.index');

    Route::get('/procurement/invoices/capture', \App\Modules\Procurement\Livewire\SupplierInvoices\Capture::class)
        ->middleware('can:procurement.invoice_create')->name('procurement.invoices.capture');

    Route::get('/procurement/payments', \App\Modules\Procurement\Livewire\Payments\Index::class)
        ->middleware('can:procurement.payment_record')->name('procurement.payments.index');

    Route::get('/procurement/payments/pay', \App\Modules\Procurement\Livewire\Payments\Pay::class)
        ->middleware('can:procurement.payment_record')->name('procurement.payments.pay');

    Route::get('/procurement/payables', \App\Modules\Procurement\Livewire\PayablesDashboard::class)
        ->middleware('can:procurement.view')->name('procurement.payables');

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
 * Replaces Laravel's stock health route (see bootstrap/app.php). Left
 * unauthenticated on purpose so a monitor can poll it without holding a
 * credential - which is also why HealthController redacts absolute paths out of
 * every string before it answers.
 */
Route::get('/up', HealthController::class)->name('health');
