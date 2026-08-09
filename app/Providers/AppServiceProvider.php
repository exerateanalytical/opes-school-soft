<?php

namespace App\Providers;

use App\Modules\Academics\Livewire\ClassGroups\Index as ClassGroupsIndex;
use App\Modules\Accounting\Livewire\ChartOfAccounts\Index as ChartOfAccountsIndex;
use App\Modules\Accounting\Livewire\JournalEntries\Form as JournalEntryForm;
use App\Modules\Accounting\Livewire\JournalEntries\Index as JournalEntriesIndex;
use App\Modules\Accounting\Livewire\Reports\TrialBalance as TrialBalanceReport;
use App\Modules\Admissions\Livewire\Wizard as AdmissionsWizard;
use App\Modules\Assessment\Livewire\Marks\Entry as MarksEntry;
use App\Modules\Academics\Livewire\Settings\AcademicSettings;
use App\Modules\Academics\Livewire\Subjects\Index as SubjectsIndex;
use App\Modules\Fees\Livewire\Cashier as FeesCashier;
use App\Modules\Fees\Livewire\Invoices\Index as FeesInvoicesIndex;
use App\Modules\Fees\Livewire\Statement as FeesStatement;
use App\Modules\Guardians\Livewire\Guardians\Show as GuardiansShow;
use App\Modules\Guardians\Livewire\Students\GuardiansPanel as StudentGuardiansPanel;
use App\Modules\Identity\Livewire\Users\Index as UsersIndex;
use App\Modules\Identity\Livewire\Users\Tokens as UserTokens;
use App\Modules\Students\Livewire\Students\Index as StudentsIndex;
use App\Modules\Students\Livewire\Students\Show as StudentsShow;
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
        //
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

        // People (07-students 11). `students.index` needs the explicit name
        // for the ".index"-stripping reason above; the other two routed
        // components are named for symmetry, and the panel because it is
        // mounted by TAG (<livewire:students.guardians-panel/>) from a
        // Students-module view that must never name a Guardians class -
        // tests/Architecture/ModuleBoundaryTest.php.
        Livewire::component('students.index', StudentsIndex::class);
        Livewire::component('students.show', StudentsShow::class);
        Livewire::component('guardians.show', GuardiansShow::class);
        Livewire::component('students.guardians-panel', StudentGuardiansPanel::class);

        // Marks entry (01-assessment 17). Named explicitly for the same reason
        // as every routed component above: one mechanism finds all of them, so
        // a future rename cannot quietly change how one is resolved.
        Livewire::component('assessment.marks-entry', MarksEntry::class);

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
        Livewire::component('fees.invoices.index', FeesInvoicesIndex::class);
        Livewire::component('fees.statement', FeesStatement::class);
    }
}
