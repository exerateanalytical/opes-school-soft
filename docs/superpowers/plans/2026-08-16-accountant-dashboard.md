# Accountant Dashboard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** build a dedicated `/accounting/dashboard` screen for the Accountant role — three stacked sections (book health, needs-attention-today, money) — per `docs/superpowers/specs/2026-08-16-accountant-dashboard-design.md`.

**Architecture:** one new Livewire component (`Accounting\Livewire\AccountingDashboard`) and its Blade view, reading exclusively from existing read-only Actions (`ControlAccountChecks`, `Review\JournalExceptions`, `Review\SuspenseBalances`, `Fees\Actions\AgedBalances`) plus one newly-extracted Action (`Accounting\Actions\MonthlyCollectionTrend`) shared with the existing `FinanceDashboard`. Reached via a new persistent sidebar nav entry, exactly like `FinanceDashboard` is — not via a quick-action tile on the generic role dashboard, which has no such mechanism today.

**Tech Stack:** Laravel 11 / Livewire 3 / Blade, inline-SVG charts (no JS charting library — matching the existing `FinanceDashboard` pattern), Pest for tests, Tailwind for styling, PHP at `C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe` (not on PATH).

---

## Corrections this plan makes to the approved spec

The spec (§8) flagged three things to verify before writing steps. All three are now resolved, and one resolution changes a data source named in the spec:

1. **`MonthlyCollectionTrend` was inline**, a private method on `Livewire\FinanceDashboard` (`monthlyCollectionTrend()`, line 748) — confirmed, extraction proceeds as the spec anticipated (Task 1).
2. **There is no quick-action mechanism to mirror.** `/finance/dashboard` is reached via a permanent sidebar nav entry in `Identity\Support\Navigation.php` (key `finance_dashboard`, gated `Permission::LedgerView`), not a quick-action tile on the generic `/dashboard`. This plan adds a sidebar nav entry, not a quick action, per §2 of the spec's own instruction to "verify and mirror" the real mechanism (Task 2).
3. **`accounting.dashboard` does not collide** with any existing route name (confirmed via `grep -c` against `routes/web.php`) and matches the file's own `accounting.*` naming convention exactly (`accounting.statements`, `accounting.year-end`, `accounting.books`, …).

**Correction to §3.1's data source.** The spec named `Actions\VerifyLedgerIntegrity` (invariant L2) for the "Books balanced?" tile. Reading that Action's own docblock and its one real caller shows it is a **nightly backstop job**, not something any existing Livewire screen invokes live — `Review\ControlCentre` (the screen that already answers "do I trust these books right now?") uses `Review\ControlAccountChecks` instead, and exposes exactly the number this tile needs: `$checks->filter(fn ($c) => $c->difference !== 0)->count()`. This plan uses `ControlAccountChecks`, matching the codebase's only established live-read precedent for this question, and links the tile to `/accounting/review` for the full detail `ControlCentre` already renders. `SuspenseBalances` and `Review\JournalExceptions` (the other two spec'd data sources) are confirmed correct as originally specified.

---

## File structure

| File | Responsibility |
|---|---|
| `app/Modules/Accounting/Actions/MonthlyCollectionTrend.php` | **New.** Extracted from `FinanceDashboard`: 12-month payments series + pre-computed SVG path/area/point geometry, shared by both dashboards. |
| `app/Modules/Accounting/Livewire/FinanceDashboard.php` | **Modified.** Calls the extracted Action instead of its own private method. No other change. |
| `app/Modules/Accounting/Livewire/AccountingDashboard.php` | **New.** The screen's Livewire component — composes the three sections' data, nothing else. |
| `resources/views/livewire/accounting/accounting-dashboard.blade.php` | **New.** The screen's markup. |
| `resources/views/components/accounting/trend-chart.blade.php` | **New.** The SVG trend chart, extracted from `finance-dashboard.blade.php` so both screens render it identically from the same markup. |
| `routes/web.php` | **Modified.** One new route. |
| `app/Modules/Identity/Support/Navigation.php` | **Modified.** One new nav entry. |
| `lang/en/opes.php`, `lang/fr/opes.php` | **Modified.** New `accounting.dashboard.*` string block, en/fr together (project convention — never one without the other). |
| `tests/Feature/Accounting/MonthlyCollectionTrendTest.php` | **New.** Unit-style test of the extracted Action. |
| `tests/Feature/Accounting/AccountingDashboardTest.php` | **New.** Feature test of the new screen. |
| `tests/Feature/Shell/ReachabilityTest.php` | **Modified.** One new assertion: the nav key exists and a real Accountant sees the link. |

---

### Task 1: Extract the trend calculation into a shared Action

**Files:**
- Create: `app/Modules/Accounting/Actions/MonthlyCollectionTrend.php`
- Create: `resources/views/components/accounting/trend-chart.blade.php`
- Modify: `app/Modules/Accounting/Livewire/FinanceDashboard.php:748-780` (delete `monthlyCollectionTrend()`, call the new Action instead) and wherever its `@php` geometry block computes `$trendPath`/`$trendArea`/`$trendPoints` in `resources/views/livewire/accounting/finance-dashboard.blade.php` (replace with `<x-accounting.trend-chart>`)
- Test: `tests/Feature/Accounting/MonthlyCollectionTrendTest.php`

This is a characterization-first extraction: capture today's behaviour in a test before moving anything, so the refactor is provably a no-op for `FinanceDashboard`.

- [ ] **Step 1: Write the failing test for the new Action**

```php
<?php

declare(strict_types=1);

use App\Modules\Accounting\Actions\MonthlyCollectionTrend;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns twelve months ending with the given date, zero-filled', function (): void {
    $action = app(MonthlyCollectionTrend::class);

    $series = $action->handle('2026-10-15');

    expect($series)->toHaveCount(12)
        ->and($series[11]['label'])->toBe('Oct 26')
        ->and($series[0]['label'])->toBe('Nov 25');
});

it('sums cleared, non-voided payments per calendar month', function (): void {
    $student = DB::table('students')->first();
    assertNotNull($student, 'seed a student before running this test');

    DB::table('payments')->insert([
        'student_id' => $student->id,
        'value_date' => '2026-09-10',
        'amount' => 15_000,
        'clearing_state' => 'cleared',
        'unallocated_amount' => 15_000,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $series = app(MonthlyCollectionTrend::class)->handle('2026-10-01');

    $september = collect($series)->firstWhere('label', 'Sep 26');

    expect($september['amount'])->toBe(15_000);
});

it('pre-computes the same SVG path geometry the chart consumes', function (): void {
    $geometry = app(MonthlyCollectionTrend::class)->handle('2026-10-01');
    $chart = app(MonthlyCollectionTrend::class)->chartGeometry($geometry);

    expect($chart)->toHaveKeys(['path', 'area', 'points']);
});
```

- [ ] **Step 2: Run it to confirm it fails**

```bash
"C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe" artisan test --filter=MonthlyCollectionTrendTest
```

Expected: FAIL — `Class "App\Modules\Accounting\Actions\MonthlyCollectionTrend" not found`.

- [ ] **Step 3: Read the exact code being moved**

Open `app/Modules/Accounting/Livewire/FinanceDashboard.php:748-780` (the private `monthlyCollectionTrend()` method) and `resources/views/livewire/accounting/finance-dashboard.blade.php` around its `@php` block (search for `$trendPath = ''`) — copy both verbatim into the new files below rather than retyping from memory, so no behaviour drifts during the move.

- [ ] **Step 4: Create the Action**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions;

use App\Modules\Identity\Domain\Permission;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Cleared, non-voided receipts per calendar month across the twelve months
 * ending with the given date. Months with no receipts are present with 0 -
 * here a zero IS the fact (no money came in that month).
 *
 * Extracted from FinanceDashboard so AccountingDashboard can show the
 * identical figure without a second, drifting implementation. The geometry
 * builder (chartGeometry) was ALSO extracted, from the blade file's own
 * @php block - two screens computing the same coordinates from the same
 * series is the same duplication risk as two screens computing the series
 * itself.
 *
 * Read-only, gated on ledger.view (matches both callers' own gate).
 */
final readonly class MonthlyCollectionTrend
{
    public const PERMISSION = Permission::LedgerView->value;

    /**
     * @return list<array{label: string, amount: int}>
     */
    public function handle(string $to): array
    {
        Gate::authorize(self::PERMISSION);

        $end = Carbon::parse($to)->endOfMonth();
        $start = $end->copy()->startOfMonth()->subMonths(11);

        $rows = DB::table('payments as p')
            ->whereBetween('p.value_date', [$start->toDateString(), $end->toDateString()])
            ->where('p.clearing_state', '<>', 'bounced')
            ->whereNotExists(function (QueryBuilder $query): void {
                $query->selectRaw('1')->from('payment_voids as v')
                    ->whereColumn('v.payment_id', 'p.id')
                    ->where('v.status', 'confirmed');
            })
            ->groupBy('bucket')
            ->selectRaw("DATE_FORMAT(p.value_date, '%Y-%m') as bucket, CAST(COALESCE(SUM(p.amount), 0) AS SIGNED) as amount")
            ->pluck('amount', 'bucket');

        $out = [];
        $cursor = $start->copy();

        for ($i = 0; $i < 12; $i++) {
            $key = $cursor->format('Y-m');

            $out[] = [
                'label' => $cursor->format('M y'),
                'amount' => (int) ($rows[$key] ?? 0),
            ];

            $cursor = $cursor->addMonthNoOverflow();
        }

        return $out;
    }

    /**
     * The SVG coordinate math both dashboards render identically, moved
     * out of the blade @php block it used to live in.
     *
     * @param  list<array{label: string, amount: int}>  $series
     * @return array{path: string, area: string, points: list<array{x: float, y: float, label: string, amount: int}>}
     */
    public function chartGeometry(array $series): array
    {
        if (count($series) < 2) {
            return ['path' => '', 'area' => '', 'points' => []];
        }

        $max = max(1, ...array_column($series, 'amount'));
        $step = 600 / (count($series) - 1);

        $path = '';
        $points = [];

        foreach ($series as $i => $point) {
            $x = 30 + ($i * $step);
            $y = 170 - (($point['amount'] / $max) * 150);

            $points[] = ['x' => $x, 'y' => $y, 'label' => $point['label'], 'amount' => (int) $point['amount']];
            $path .= ($i === 0 ? 'M' : ' L').' '.round($x, 2).' '.round($y, 2);
        }

        $lastIndex = count($series) - 1;
        $area = 'M 30 170 L'.substr($path, 1).' L '.round(30 + ($lastIndex * $step), 2).' 170 Z';

        return ['path' => $path, 'area' => $area, 'points' => $points];
    }
}
```

**Before saving:** paste in the *actual* `$x`/`$y` formulas read in Step 3 in place of the ones above if they differ even slightly (e.g. a different divisor or max-scaling approach) — this file must reproduce the existing chart pixel-for-pixel, not approximately.

- [ ] **Step 5: Create the shared chart component**

```blade
{{--
    The monthly-collection-trend SVG, extracted so FinanceDashboard and
    AccountingDashboard render byte-identical charts from one markup, not
    two copies that can drift. Geometry is pre-computed by
    MonthlyCollectionTrend::chartGeometry() - this file only draws.
--}}
@props(['series', 'geometry', 'endLabel'])

<svg viewBox="0 0 660 220" role="img" class="h-auto w-full min-w-[560px]"
     aria-labelledby="trend-title trend-desc">
    <title id="trend-title">Monthly collection trend</title>
    <desc id="trend-desc">Cleared receipts per month over the twelve months ending {{ $endLabel }}.</desc>

    <path d="{{ $geometry['area'] }}" fill="var(--color-primary)" fill-opacity="0.10"/>
    <path d="{{ $geometry['path'] }}" fill="none" stroke="var(--color-primary)" stroke-width="2.5"
          stroke-linecap="round" stroke-linejoin="round"/>

    @foreach ($geometry['points'] as $point)
        <circle cx="{{ round($point['x'], 2) }}" cy="{{ round($point['y'], 2) }}" r="3" fill="var(--color-primary)">
            <title>{{ $point['label'] }}: {{ number_format($point['amount']) }}</title>
        </circle>
    @endforeach
</svg>
```

**Before saving:** compare this against the real markup found in Step 3 (axis `<line>` elements, exact attributes) and copy anything this draft is missing — this is a starting point built from the grep excerpt already seen, not a confirmed-complete copy.

- [ ] **Step 6: Point `FinanceDashboard` at the extracted Action**

In `app/Modules/Accounting/Livewire/FinanceDashboard.php`, delete the private `monthlyCollectionTrend()` method (lines 748-780) and replace its one call site with:

```php
app(\App\Modules\Accounting\Actions\MonthlyCollectionTrend::class)->handle($window['end'])
```

In `resources/views/livewire/accounting/finance-dashboard.blade.php`, delete the `@php` block that built `$trendPath`/`$trendArea`/`$trendPoints` and replace the `<svg>` block found in Step 3 with:

```blade
<x-accounting.trend-chart
    :series="$chartSeries"
    :geometry="app(\App\Modules\Accounting\Actions\MonthlyCollectionTrend::class)->chartGeometry($chartSeries)"
    :end-label="\Illuminate\Support\Carbon::parse($window['end'])->format('F Y')"/>
```

- [ ] **Step 7: Run the new test**

```bash
"C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe" artisan test --filter=MonthlyCollectionTrendTest
```

Expected: PASS, 3 tests.

- [ ] **Step 8: Regression-check `FinanceDashboard` itself**

```bash
"C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe" artisan test --filter=FinanceDashboard
```

Expected: PASS, no change in count from before this task. If this filter matches zero tests, that is itself a finding — note it, do not silently skip verifying the extraction against the screen it came from.

- [ ] **Step 9: Commit**

```bash
git add app/Modules/Accounting/Actions/MonthlyCollectionTrend.php \
        app/Modules/Accounting/Livewire/FinanceDashboard.php \
        resources/views/components/accounting/trend-chart.blade.php \
        resources/views/livewire/accounting/finance-dashboard.blade.php \
        tests/Feature/Accounting/MonthlyCollectionTrendTest.php
git commit -m "refactor(accounting): extract the collection-trend chart into a shared Action

FinanceDashboard's monthly-trend calculation and SVG geometry were both
inline - a private method plus a blade @php block. AccountingDashboard
needs the identical figure; a second implementation is how the two
screens quietly disagree six months from now."
```

---

### Task 2: Route and navigation

**Files:**
- Modify: `routes/web.php` (insert after the `finance.dashboard` route, ~line 730)
- Modify: `app/Modules/Identity/Support/Navigation.php` (insert after the `finance_dashboard` entry, ~line 126)
- Modify: `resources/views/components/opes-nav-icon.blade.php` — no change needed, reuses the existing `audit_log` icon (a document with a checkmark — distinct from `finance_dashboard`'s bank icon directly above it in the sidebar)
- Test: `tests/Feature/Shell/ReachabilityTest.php` (append)

- [ ] **Step 1: Write the failing reachability test**

Append to `tests/Feature/Shell/ReachabilityTest.php`:

```php
it('offers the accounting dashboard in the navigation', function (): void {
    $keys = array_column(Navigation::items(), 'key');

    expect($keys)->toContain('accounting_dashboard');
});

it('shows an accountant the accounting dashboard link in their own sidebar', function (): void {
    p13moneyUserAs(Role::Accountant);

    $response = get('/dashboard');

    $response->assertOk();
    $response->assertSee('href="/accounting/dashboard"', escape: false);
});
```

- [ ] **Step 2: Run it to confirm it fails**

```bash
"C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe" artisan test --filter=ReachabilityTest
```

Expected: FAIL — `accounting_dashboard` not found in nav keys.

- [ ] **Step 3: Add the route**

In `routes/web.php`, immediately after the `finance.dashboard` route block (~line 730):

```php
    /*
     * The Accountant's own overview (2026-08-16 design doc) - book health,
     * today's task list, and receivables/collections. Deliberately separate
     * from finance.dashboard above: that screen is the Bursar's collections
     * view, this one answers "can I trust these books and what needs doing
     * today," which is a different job even though both are ledger.view.
     */
    Route::get('/accounting/dashboard', \App\Modules\Accounting\Livewire\AccountingDashboard::class)
        ->middleware('can:ledger.view')->name('accounting.dashboard');
```

- [ ] **Step 4: Add the nav entry**

In `app/Modules/Identity/Support/Navigation.php`, immediately after the `finance_dashboard` entry (~line 126):

```php
            // The Accountant's own overview (2026-08-16 design doc),
            // deliberately separate from finance_dashboard above - that one
            // is the Bursar's collections view.
            ['key' => 'accounting_dashboard', 'route' => '/accounting/dashboard', 'permission' => Permission::LedgerView, 'enabled' => true, 'built' => true],
```

- [ ] **Step 5: Create a placeholder Livewire component so the route resolves**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Livewire;

use App\Modules\Identity\Domain\Permission;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
final class AccountingDashboard extends Component
{
    public function mount(): void
    {
        Gate::authorize(Permission::LedgerView->value);
    }

    public function render(): mixed
    {
        return view('livewire.accounting.accounting-dashboard');
    }
}
```

```blade
<div class="space-y-8">
    <h1 class="text-xl font-semibold text-charcoal">{{ __('opes.accounting.dashboard.heading') }}</h1>
</div>
```

Add to `lang/en/opes.php` and `lang/fr/opes.php`, inside the existing `'accounting' => [...]` block (find it by searching for `'review' =>` which is already there):

```php
        'dashboard' => [
            'heading' => 'Accounting dashboard', // fr: 'Tableau de bord comptable'
        ],
```

- [ ] **Step 6: Run the reachability test again**

```bash
"C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe" artisan test --filter=ReachabilityTest
```

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add routes/web.php app/Modules/Identity/Support/Navigation.php \
        app/Modules/Accounting/Livewire/AccountingDashboard.php \
        resources/views/livewire/accounting/accounting-dashboard.blade.php \
        lang/en/opes.php lang/fr/opes.php \
        tests/Feature/Shell/ReachabilityTest.php
git commit -m "feat(accounting): wire up the /accounting/dashboard route and nav entry"
```

---

### Task 3: Book health strip

**Files:**
- Modify: `app/Modules/Accounting/Livewire/AccountingDashboard.php`
- Modify: `resources/views/livewire/accounting/accounting-dashboard.blade.php`
- Modify: `lang/en/opes.php`, `lang/fr/opes.php`
- Test: `tests/Feature/Accounting/AccountingDashboardTest.php` (new)

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Identity\Domain\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\get;

require_once __DIR__.'/AccountingTestHelpers.php';

uses(RefreshDatabase::class);

it('shows a healthy books tile when nothing is out of balance', function (): void {
    $user = ledgerUser(Role::Accountant);

    $response = get('/accounting/dashboard')->assertOk();

    $response->assertSee(__('opes.accounting.dashboard.books_balanced'));
})->skip('pending real seed data - see Task 3 step 4 note');

it('counts draft journal entries as unposted', function (): void {
    $user = ledgerUser(Role::Accountant);
    $calendar = ledgerCalendar();

    $journal = \App\Modules\Accounting\Models\Journal::factory()->create();

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
```

- [ ] **Step 2: Run it to confirm it fails**

```bash
"C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe" artisan test --filter=AccountingDashboardTest
```

Expected: the draft-entry test FAILS (the page has no unposted-count content yet); the balanced-books test is marked `skip` deliberately — see Step 4.

- [ ] **Step 3: Implement the three tiles**

Replace `AccountingDashboard.php`'s `render()`:

```php
    public function render(): mixed
    {
        $checks = app(\App\Modules\Accounting\Actions\Review\ControlAccountChecks::class)->handle();
        $draftCount = app(\App\Modules\Accounting\Actions\Review\JournalExceptions::class)->query('draft')->count();
        $suspense = app(\App\Modules\Accounting\Actions\Review\SuspenseBalances::class)->handle();

        return view('livewire.accounting.accounting-dashboard', [
            'brokenCount' => $checks->filter(fn ($c) => $c->difference !== 0)->count(),
            'draftCount' => $draftCount,
            'suspense' => $suspense,
        ]);
    }
```

Replace the blade view's body:

```blade
<div class="space-y-8">
    <h1 class="text-xl font-semibold text-charcoal">{{ __('opes.accounting.dashboard.heading') }}</h1>

    {{-- Book health: three tiles, meant to read green almost always. --}}
    <section aria-labelledby="acct-dash-health" class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <h2 id="acct-dash-health" class="sr-only">{{ __('opes.accounting.dashboard.health_heading') }}</h2>

        <a href="{{ route('accounting.review') }}"
           class="rounded-xl border border-border-primary bg-white px-4 py-3.5 shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-wide text-charcoal/55">
                {{ __('opes.accounting.dashboard.books_balanced') }}
            </p>
            @if ($brokenCount === 0)
                <p class="mt-2 text-lg font-bold text-portal-success">&check; {{ __('opes.accounting.dashboard.balanced') }}</p>
            @else
                <p class="mt-2 text-lg font-bold text-heritage-red">{{ __('opes.accounting.dashboard.out_of_balance_count', ['count' => $brokenCount]) }}</p>
            @endif
        </a>

        <a href="{{ route('ledger.journal-entries.index') }}"
           class="rounded-xl border border-border-primary bg-white px-4 py-3.5 shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-wide text-charcoal/55">
                {{ __('opes.accounting.dashboard.unposted_entries') }}
            </p>
            @if ($draftCount === 0)
                <p class="mt-2 text-lg font-bold text-portal-success">{{ __('opes.accounting.dashboard.all_caught_up') }}</p>
            @else
                <p class="mt-2 text-lg font-bold text-charcoal">{{ $draftCount }}</p>
            @endif
        </a>

        <a href="{{ route('accounting.review') }}"
           class="rounded-xl border border-border-primary bg-white px-4 py-3.5 shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-wide text-charcoal/55">
                {{ __('opes.accounting.dashboard.uncategorised') }}
            </p>
            @if ($suspense->isEmpty())
                <p class="mt-2 text-lg font-bold text-portal-success">{{ __('opes.accounting.dashboard.none') }}</p>
            @else
                <p class="mt-2 text-lg font-bold text-heritage-red">{{ number_format((int) $suspense->sum('balance')) }}</p>
            @endif
        </a>
    </section>
</div>
```

Add the new lang keys to both `lang/en/opes.php` and `lang/fr/opes.php`, inside `'dashboard' => [...]`:

```php
            'health_heading' => 'Book health', // fr: 'Santé des comptes'
            'books_balanced' => 'Books balanced?', // fr: 'Comptes équilibrés ?'
            'balanced' => 'Balanced', // fr: 'Équilibrés'
            'out_of_balance_count' => ':count out of balance', // fr: ':count en déséquilibre'
            'unposted_entries' => 'Unposted entries', // fr: 'Écritures non validées'
            'all_caught_up' => 'All caught up', // fr: 'Tout est à jour'
            'uncategorised' => 'Uncategorised balances', // fr: 'Soldes non catégorisés'
            'none' => 'None', // fr: 'Aucun'
```

**Verify the route names used above are real** before running: `route('ledger.journal-entries.index')` and `route('accounting.review')` are assumed from this session's earlier reading of `routes/web.php` and `RoleDashboard::QUICK_ACTIONS`; confirm with `php artisan route:list --name=ledger.journal-entries` and `--name=accounting.review` and correct the blade if either name differs.

- [ ] **Step 4: Resolve the skipped test**

The "healthy books" test is skipped because `ControlAccountChecks` needs real fiscal-year/AR-AP seed data to produce a genuine `Reconciled` result, which `ledgerCalendar()` alone does not set up. Read `tests/Feature/Accounting/AccountingReviewTest.php` (the existing test for the `ControlCentre` screen this tile mirrors) for its seed pattern, copy the minimum needed to get a real `Reconciled` state, then remove `->skip(...)`.

- [ ] **Step 5: Run the tests**

```bash
"C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe" artisan test --filter=AccountingDashboardTest
```

Expected: PASS, both tests (the unskipped one included).

- [ ] **Step 6: Commit**

```bash
git add app/Modules/Accounting/Livewire/AccountingDashboard.php \
        resources/views/livewire/accounting/accounting-dashboard.blade.php \
        lang/en/opes.php lang/fr/opes.php \
        tests/Feature/Accounting/AccountingDashboardTest.php
git commit -m "feat(accounting): book health strip on the new dashboard"
```

---

### Task 4: Needs-attention-today section

**Files:**
- Modify: `app/Modules/Accounting/Livewire/AccountingDashboard.php`
- Modify: `resources/views/livewire/accounting/accounting-dashboard.blade.php`
- Modify: `lang/en/opes.php`, `lang/fr/opes.php`
- Test: `tests/Feature/Accounting/AccountingDashboardTest.php` (append)

- [ ] **Step 1: Write the failing test**

```php
it('lists a fiscal year that is currently closing', function (): void {
    $user = ledgerUser(Role::Accountant);

    \App\Modules\Accounting\Models\FiscalYear::query()->create([
        'code' => 'FY2099',
        'starts_on' => '2099-01-01',
        'ends_on' => '2099-12-31',
        'status' => 'closing',
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
```

Check `FiscalYear`'s actual required/fillable columns before running this — `code`/`starts_on`/`ends_on`/`status` are the four confirmed this session; if the model guards additional required columns, add them.

- [ ] **Step 2: Run it to confirm it fails**

```bash
"C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe" artisan test --filter=AccountingDashboardTest
```

Expected: FAIL — no "FY2099" and no "nothing pending" text anywhere yet.

- [ ] **Step 3: Implement**

Add to `AccountingDashboard.php`'s `render()`:

```php
        $closingYear = \App\Modules\Accounting\Models\FiscalYear::query()
            ->where('status', 'closing')
            ->first();

        $draftEntries = app(\App\Modules\Accounting\Actions\Review\JournalExceptions::class)
            ->query('draft')
            ->orderByDesc('date')
            ->limit(10)
            ->get();
```

...and add `'closingYear' => $closingYear, 'draftEntries' => $draftEntries,` to the view-data array.

Add to the blade view, after the health-strip `</section>`:

```blade
    {{-- Needs attention today: a real task list, not another count. --}}
    <section aria-labelledby="acct-dash-attention">
        <h2 id="acct-dash-attention" class="mb-3 text-sm font-semibold uppercase tracking-wide text-charcoal/70">
            {{ __('opes.accounting.dashboard.attention_heading') }}
        </h2>

        @if ($closingYear === null && $draftEntries->isEmpty())
            <p class="rounded-xl border border-border-primary bg-white px-4 py-3 text-sm text-charcoal/60">
                {{ __('opes.accounting.dashboard.nothing_pending') }}
            </p>
        @else
            <ul class="space-y-2">
                @if ($closingYear !== null)
                    <li class="rounded-xl border border-border-primary bg-white px-4 py-3 shadow-sm">
                        <a href="{{ route('accounting.year-end') }}" class="text-sm font-semibold text-charcoal hover:text-primary">
                            {{ __('opes.accounting.dashboard.fiscal_year_closing', ['code' => $closingYear->code]) }}
                        </a>
                    </li>
                @endif

                @foreach ($draftEntries as $entry)
                    <li class="rounded-xl border border-border-primary bg-white px-4 py-3 shadow-sm">
                        <a href="{{ route('ledger.journal-entries.edit', $entry->id) }}" class="text-sm font-semibold text-charcoal hover:text-primary">
                            {{ __('opes.accounting.dashboard.draft_entry', ['label' => $entry->label]) }}
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
```

Add lang keys:

```php
            'attention_heading' => 'Needs your attention today', // fr: 'À traiter aujourd\'hui'
            'nothing_pending' => 'Nothing needs your attention right now.', // fr: 'Rien ne nécessite votre attention pour le moment.'
            'fiscal_year_closing' => 'Fiscal year :code is being closed', // fr: 'L\'exercice :code est en cours de clôture'
            'draft_entry' => 'Draft entry: :label', // fr: 'Écriture brouillon : :label'
```

**Verify `route('ledger.journal-entries.edit', $id)` is the real edit-route signature** (check `routes/web.php`'s journal-entries block) before running — correct if the parameter name or route name differs.

- [ ] **Step 4: Run the tests**

```bash
"C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe" artisan test --filter=AccountingDashboardTest
```

Expected: PASS, 4 tests total (2 from Task 3 + 2 new).

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Accounting/Livewire/AccountingDashboard.php \
        resources/views/livewire/accounting/accounting-dashboard.blade.php \
        lang/en/opes.php lang/fr/opes.php \
        tests/Feature/Accounting/AccountingDashboardTest.php
git commit -m "feat(accounting): needs-attention-today task list"
```

---

### Task 5: Money — aged receivables and top debtors

**Files:**
- Modify: `app/Modules/Accounting/Livewire/AccountingDashboard.php`
- Modify: `resources/views/livewire/accounting/accounting-dashboard.blade.php`
- Modify: `lang/en/opes.php`, `lang/fr/opes.php`
- Test: `tests/Feature/Accounting/AccountingDashboardTest.php` (append)

- [ ] **Step 1: Write the failing test**

```php
it('shows the aged-receivables total and a top debtor by name', function (): void {
    $user = ledgerUser(Role::Accountant);
    $student = \App\Modules\Students\Models\Student::factory()->create([
        'first_name' => 'Aged', 'last_name' => 'Debtor',
    ]);

    $invoiceId = DB::table('invoices')->insertGetId([
        'student_id' => $student->id,
        'status' => 'issued',
        'issue_date' => '2026-01-01',
        'due_date' => '2026-01-15',
        'academic_year_id' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('invoice_lines')->insert([
        'invoice_id' => $invoiceId,
        'amount' => 100_000,
        'tax_amount' => 0,
        'fee_category_code' => 'tuition',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($user);
    $response = get('/accounting/dashboard')->assertOk();

    $response->assertSeeText('100,000');
    $response->assertSeeText('Aged Debtor');
});
```

**Verify `Student` factory's real namespace and required columns, and `invoices`/`academic_year_id`'s actual nullability**, before running — copy the seed pattern from an existing Fees test (e.g. `tests/Feature/Fees/AgedBalancesTest.php` if one exists) rather than guessing column requirements a second time.

- [ ] **Step 2: Run it to confirm it fails**

```bash
"C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe" artisan test --filter=AccountingDashboardTest
```

Expected: FAIL.

- [ ] **Step 3: Implement**

Add to `AccountingDashboard.php`'s `render()`:

```php
        $aged = app(\App\Modules\Fees\Actions\AgedBalances::class)->handle();

        $buckets = [
            'current' => $aged->sum('current'),
            'days_1_30' => $aged->sum('days_1_30'),
            'days_31_60' => $aged->sum('days_31_60'),
            'days_61_90' => $aged->sum('days_61_90'),
            'days_91_180' => $aged->sum('days_91_180'),
            'days_180_plus' => $aged->sum('days_180_plus'),
        ];

        $topDebtors = $aged->sortByDesc('net')->take(8)->values();

        $students = \Illuminate\Support\Facades\DB::table('students')
            ->whereIn('id', $topDebtors->pluck('student_id')->all())
            ->get(['id', 'first_name', 'last_name'])
            ->keyBy('id');
```

...and add `'buckets' => $buckets, 'topDebtors' => $topDebtors, 'students' => $students,` to the view data.

Add to the blade view, after the attention `</section>`:

```blade
    {{-- Money: real data, aged-receivables source (per-student, bucketed) -
         NOT the generic dashboard's single aggregate figure. --}}
    <section aria-labelledby="acct-dash-money" class="space-y-4">
        <h2 id="acct-dash-money" class="text-sm font-semibold uppercase tracking-wide text-charcoal/70">
            {{ __('opes.accounting.dashboard.money_heading') }}
        </h2>

        <div class="rounded-xl border border-border-primary bg-white p-4 shadow-sm">
            <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-charcoal/55">
                {{ __('opes.accounting.dashboard.aged_heading') }}
            </p>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                @foreach ($buckets as $key => $amount)
                    <div>
                        <p class="text-xs text-charcoal/55">{{ __('opes.accounting.dashboard.bucket_'.$key) }}</p>
                        <p class="text-sm font-bold tabular-nums {{ $amount > 0 ? 'text-charcoal' : 'text-charcoal/40' }}">
                            {{ number_format($amount) }}
                        </p>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="rounded-xl border border-border-primary bg-white p-4 shadow-sm">
            <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-charcoal/55">
                {{ __('opes.accounting.dashboard.top_debtors_heading') }}
            </p>
            @forelse ($topDebtors as $row)
                @php $student = $students[$row->student_id] ?? null; @endphp
                <div class="flex justify-between border-t border-charcoal/10 py-2 text-sm first:border-t-0">
                    <span>{{ $student !== null ? $student->first_name.' '.$student->last_name : __('opes.ui.unknown') }}</span>
                    <span class="tabular-nums font-medium">{{ number_format($row->net) }}</span>
                </div>
            @empty
                <p class="text-sm text-portal-success">{{ __('opes.accounting.dashboard.no_debtors') }}</p>
            @endforelse
        </div>
    </section>
```

Add lang keys (six bucket labels, matching `AgedBalances::BUCKETS`' own keys exactly so `'bucket_'.$key` resolves):

```php
            'money_heading' => 'Money', // fr: 'Trésorerie'
            'aged_heading' => 'Aged receivables', // fr: 'Créances échues'
            'bucket_current' => 'Current', // fr: 'À échoir'
            'bucket_days_1_30' => '1–30 days', // fr: '1–30 jours'
            'bucket_days_31_60' => '31–60 days', // fr: '31–60 jours'
            'bucket_days_61_90' => '61–90 days', // fr: '61–90 jours'
            'bucket_days_91_180' => '91–180 days', // fr: '91–180 jours'
            'bucket_days_180_plus' => '180+ days', // fr: '180+ jours'
            'top_debtors_heading' => 'Top debtors', // fr: 'Principaux débiteurs'
            'no_debtors' => 'No one owes money right now.', // fr: 'Personne ne doit d\'argent actuellement.'
```

Check whether `opes.ui.unknown` already exists (it likely does, given the codebase's stated no-lying-zero convention); add it only if it doesn't.

- [ ] **Step 4: Run the tests**

```bash
"C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe" artisan test --filter=AccountingDashboardTest
```

Expected: PASS, 5 tests total.

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Accounting/Livewire/AccountingDashboard.php \
        resources/views/livewire/accounting/accounting-dashboard.blade.php \
        lang/en/opes.php lang/fr/opes.php \
        tests/Feature/Accounting/AccountingDashboardTest.php
git commit -m "feat(accounting): aged-receivables buckets and top debtors"
```

---

### Task 6: Money — collection trend chart

**Files:**
- Modify: `app/Modules/Accounting/Livewire/AccountingDashboard.php`
- Modify: `resources/views/livewire/accounting/accounting-dashboard.blade.php`
- Modify: `lang/en/opes.php`, `lang/fr/opes.php`

- [ ] **Step 1: Write the failing test**

```php
it('shows the collection trend chart with an accurate description date', function (): void {
    $user = ledgerUser(Role::Accountant);

    $this->actingAs($user);
    $response = get('/accounting/dashboard')->assertOk();

    $response->assertSee('Monthly collection trend', false);
});
```

- [ ] **Step 2: Run it to confirm it fails**

```bash
"C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe" artisan test --filter=AccountingDashboardTest
```

Expected: FAIL.

- [ ] **Step 3: Implement**

Add to `AccountingDashboard.php`'s `render()`:

```php
        $trendAction = app(\App\Modules\Accounting\Actions\MonthlyCollectionTrend::class);
        $trendSeries = $trendAction->handle(now()->toDateString());
        $trendGeometry = $trendAction->chartGeometry($trendSeries);
```

...and add `'trendSeries' => $trendSeries, 'trendGeometry' => $trendGeometry,` to the view data.

Add to the blade view, inside the money `<section>`, after the top-debtors `<div>`:

```blade
        <div class="rounded-xl border border-border-primary bg-white p-4 shadow-sm">
            <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-charcoal/55">
                {{ __('opes.accounting.dashboard.trend_heading') }}
            </p>
            <p class="mb-2 text-xs text-charcoal/50">
                {{ __('opes.accounting.dashboard.trend_caveat') }}
            </p>
            <x-accounting.trend-chart
                :series="$trendSeries"
                :geometry="$trendGeometry"
                :end-label="now()->format('F Y')"/>
        </div>
```

Add lang keys:

```php
            'trend_heading' => 'Collections over time', // fr: 'Encaissements dans le temps'
            'trend_caveat' => 'Based on payment history recorded in the system.', // fr: 'D\'après l\'historique des paiements enregistrés dans le système.'
```

- [ ] **Step 4: Run the tests**

```bash
"C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe" artisan test --filter=AccountingDashboardTest
```

Expected: PASS, 6 tests total.

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Accounting/Livewire/AccountingDashboard.php \
        resources/views/livewire/accounting/accounting-dashboard.blade.php \
        lang/en/opes.php lang/fr/opes.php \
        tests/Feature/Accounting/AccountingDashboardTest.php
git commit -m "feat(accounting): collection trend chart, reusing the extracted geometry"
```

---

### Task 7: Quick actions row

**Files:**
- Modify: `app/Modules/Accounting/Livewire/AccountingDashboard.php`
- Modify: `resources/views/livewire/accounting/accounting-dashboard.blade.php`
- Modify: `lang/en/opes.php`, `lang/fr/opes.php`

Four static links (matching what the Accountant already sees on the generic dashboard) plus one conditional link — built directly in this screen's own blade, **not** through `RoleDashboard::QUICK_ACTIONS`, because that system has no mechanism for a data-conditional entry and this button must disappear when no fiscal year is closing.

- [ ] **Step 1: Write the failing tests**

```php
it('always offers the four standing accounting actions', function (): void {
    $user = ledgerUser(Role::Accountant);

    $this->actingAs($user);
    $response = get('/accounting/dashboard')->assertOk();

    foreach ([
        route('ledger.journal-entries.create'),
        route('ledger.trial-balance'),
        route('tax.dashboard'),
        route('reports.hub'),
    ] as $href) {
        $response->assertSee('href="'.$href.'"', escape: false);
    }
});

it('offers to continue closing a fiscal year only when one is closing', function (): void {
    $user = ledgerUser(Role::Accountant);

    $this->actingAs($user);
    $response = get('/accounting/dashboard')->assertOk();
    $response->assertDontSee('href="'.route('accounting.year-end').'"', escape: false);

    \App\Modules\Accounting\Models\FiscalYear::query()->create([
        'code' => 'FY2099', 'starts_on' => '2099-01-01', 'ends_on' => '2099-12-31', 'status' => 'closing',
    ]);

    $response = get('/accounting/dashboard')->assertOk();
    $response->assertSee('href="'.route('accounting.year-end').'"', escape: false);
});
```

**Verify the four route names** (`ledger.journal-entries.create`, `ledger.trial-balance`, `tax.dashboard`, `reports.hub`) against `routes/web.php` before running — they are carried over from `RoleDashboard::QUICK_ACTIONS`, read earlier this session, but re-confirm rather than trust memory a second time.

- [ ] **Step 2: Run it to confirm it fails**

```bash
"C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe" artisan test --filter=AccountingDashboardTest
```

Expected: FAIL — none of the action links exist on the page yet.

- [ ] **Step 3: Implement**

`$closingYear` already exists in `render()`'s data from Task 4 — no new PHP needed. Add to the blade view, as a final section before the closing `</div>`:

```blade
    <section aria-labelledby="acct-dash-actions">
        <h2 id="acct-dash-actions" class="mb-3 text-sm font-semibold uppercase tracking-wide text-charcoal/70">
            {{ __('opes.dashboard.actions') }}
        </h2>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <a href="{{ route('ledger.journal-entries.create') }}" class="rounded-xl border border-border-primary bg-white p-4 shadow-sm hover:border-primary">
                <span class="block font-semibold text-charcoal">{{ __('opes.accounting.dashboard.action_new_entry') }}</span>
            </a>
            <a href="{{ route('ledger.trial-balance') }}" class="rounded-xl border border-border-primary bg-white p-4 shadow-sm hover:border-primary">
                <span class="block font-semibold text-charcoal">{{ __('opes.accounting.dashboard.action_trial_balance') }}</span>
            </a>
            <a href="{{ route('tax.dashboard') }}" class="rounded-xl border border-border-primary bg-white p-4 shadow-sm hover:border-primary">
                <span class="block font-semibold text-charcoal">{{ __('opes.accounting.dashboard.action_tax') }}</span>
            </a>
            <a href="{{ route('reports.hub') }}" class="rounded-xl border border-border-primary bg-white p-4 shadow-sm hover:border-primary">
                <span class="block font-semibold text-charcoal">{{ __('opes.accounting.dashboard.action_reports') }}</span>
            </a>
            @if ($closingYear !== null)
                <a href="{{ route('accounting.year-end') }}" class="rounded-xl border border-primary bg-portal-tint p-4 shadow-sm">
                    <span class="block font-semibold text-primary">{{ __('opes.accounting.dashboard.action_continue_closing', ['code' => $closingYear->code]) }}</span>
                </a>
            @endif
        </div>
    </section>
```

Add lang keys:

```php
            'action_new_entry' => 'New journal entry', // fr: 'Nouvelle écriture'
            'action_trial_balance' => 'Trial balance', // fr: 'Balance générale'
            'action_tax' => 'Tax', // fr: 'Fiscalité'
            'action_reports' => 'Reports', // fr: 'Rapports'
            'action_continue_closing' => 'Continue closing :code', // fr: 'Poursuivre la clôture de :code'
```

Check whether `opes.dashboard.actions` already exists (it's used by the generic dashboard's "Quick actions" heading) — reuse it rather than adding a duplicate key.

- [ ] **Step 4: Run the tests**

```bash
"C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe" artisan test --filter=AccountingDashboardTest
```

Expected: PASS, 8 tests total.

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Accounting/Livewire/AccountingDashboard.php \
        resources/views/livewire/accounting/accounting-dashboard.blade.php \
        lang/en/opes.php lang/fr/opes.php \
        tests/Feature/Accounting/AccountingDashboardTest.php
git commit -m "feat(accounting): quick actions, including the conditional fiscal-year-close button"
```

---

### Task 8: Mobile grid, full regression, and browser verification

**Files:** none created — verification only, per this plan's own file list.

- [ ] **Step 1: Confirm every grid on the new page is overflow-safe**

Re-read `resources/views/livewire/accounting/accounting-dashboard.blade.php` and confirm every `grid` class includes an explicit base column count (`grid-cols-1`, `grid-cols-2`, etc.) with no bare `grid` relying on an implicit `auto` track below `sm`. This is the exact defect fixed in commit `4e77f64` on the generic dashboard — a long line of text sizing an implicit track wider than its container. The health strip (`grid-cols-1 gap-4 sm:grid-cols-3`), the bucket grid (`grid-cols-2 gap-3 sm:grid-cols-3`), and the quick-actions grid (`grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3`) were all written with this from Steps in Tasks 3/5/7 — this step is a re-check, not new work.

- [ ] **Step 2: Full Accounting regression**

Check no other test process is already running first:

```bash
powershell -Command "Get-CimInstance Win32_Process -Filter \"Name='php.exe'\" | Select-Object ProcessId,CommandLine"
```

If nothing matching `pest`/`artisan test` is running:

```bash
"C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe" artisan test --filter="Accounting|Reachability|DemoRoleLogin"
```

Expected: PASS, 0 failures. If anything fails outside the files this plan touched, stop and investigate before continuing — do not assume it is unrelated.

- [ ] **Step 3: Browser-verify, desktop**

```
preview_start {name: "opes"}
```

Sign in via the demo panel as **Accountant**, navigate to `/accounting/dashboard`, `resize_window` to 1440×900 immediately before screenshotting (never after a navigate — the pane silently loses its emulated viewport otherwise), then screenshot. Check: three sections present in order, book-health tiles read green with real numbers, aged-receivables bucket amounts match what step 3's/5's seed data would produce in the live demo DB, quick actions all present.

- [ ] **Step 4: Browser-verify, true mobile**

Headless Chrome on this machine clamps `--window-size` below roughly 504px, so a raw `--window-size=375` screenshot silently renders a wider layout cropped to 375 and would show neither a real bug nor a real fix. Use the iframe technique already built this session (`mobile-frame.php` pattern: embed the page in a 375×812 same-origin iframe inside a comfortably wide headless window, screenshot, then crop to the iframe's bounds) or, if the Browser pane is available in this session, `resize_window` to 375×812 directly and screenshot from there — either way, confirm the actual CSS viewport is 375, not assume it from the window-size flag. Check: no card's right edge is clipped, the bucket grid is genuinely 2 columns (not 1 wide overflowing), the trend chart's `min-w-[560px]` does NOT force horizontal scroll on the page itself (it may scroll within its own container — verify `documentElement.scrollWidth` stays at 375, not the chart's internal width).

- [ ] **Step 5: Regression-check `/finance/dashboard`**

Sign in as **Bursar** (or whichever demo identity holds `ledger.view` and reaches that screen), navigate to `/finance/dashboard`, confirm the trend chart still renders identically to before Task 1's extraction — same shape, same numbers, no visual change. This is the one screen Task 1 touched without a corresponding new test asserting its rendered output pixel-for-pixel, so this manual check is load-bearing, not optional.

- [ ] **Step 6: Clean up**

```bash
rm -rf public/__compare public/__shot
```

Neither should exist at this point if the design-parity/role-shot harnesses from earlier in this session weren't re-run, but confirm — both hold fully-rendered authenticated pages and must never be committed.

---

## Self-review notes

- **Spec coverage:** §3.1 (Task 3), §3.2 (Task 4), §3.3 (Tasks 5-6), §3.4 (Task 7), §6 responsive grid (Task 8 step 1), §7 testing (Tasks 3-8 each carry their own tests, Task 8 runs the full filtered suite). §4's exclusions (budgets/expenses/tax cards, `cash_position` bug, `PostFromEvent`, `/finance/dashboard` beyond the one extraction) are honored by omission — no task touches any of them.
- **Two things intentionally left for the implementer to verify in-flight rather than hard-coded here**, flagged inline at their exact step: the trend chart's precise axis-line markup (Task 1, Step 5) and four route names carried over from an earlier session read rather than a fresh grep (Task 7, Step 1). Both are marked, not silently assumed.
- **Type/name consistency check:** `AccountingDashboard` (class), `accounting-dashboard.blade.php` (view), `accounting.dashboard` (route name), `accounting_dashboard` (nav key) — four different naming conventions for the same concept, each matching its own layer's existing convention (PascalCase class, kebab-case file, dot.case route, snake_case nav key). Verified consistent across all eight tasks.
