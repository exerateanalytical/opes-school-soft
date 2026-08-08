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
use App\Modules\Guardians\Livewire\Guardians\Show as GuardiansShow;
use App\Modules\Guardians\Livewire\Students\GuardiansPanel as StudentGuardiansPanel;
use App\Modules\Identity\Livewire\Users\Index as UsersIndex;
use App\Modules\Students\Livewire\Students\Index as StudentsIndex;
use App\Modules\Students\Livewire\Students\Show as StudentsShow;
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
        // Livewire infers a component's public name from its class name, and
        // strips a trailing ".index" segment on the assumption the class
        // itself is reachable via a sibling namespace lookup (09-ui 8.10's
        // Users\Index has no such sibling). Left to the default resolver,
        // routing straight to the class - as routes/web.php does - throws
        // ComponentNotFoundException. An explicit name sidesteps the
        // stripping logic entirely (Finder::normalizeName resolves it before
        // ever reaching the ".index" special case).
        Livewire::component('users.index', UsersIndex::class);

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
    }
}
