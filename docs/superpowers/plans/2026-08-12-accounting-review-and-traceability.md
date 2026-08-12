# Accounting Review & Traceability Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the accountant a read-only assurance surface that answers "do I trust these books right now?", where every figure drills down to the journal lines and source document that compose it.

**Architecture:** Two additive layers over the existing SYSCOHADA ledger. A *traceability spine* (`ResolveSourceDocument` + a Blade drill-down component) that turns the `source_type`/`source_id` columns already on `journal_entries` into navigable links. Then an *Accounting Review* subsystem (`App\Modules\Accounting\Livewire\Review\*`) that computes control-account identities, lists journal exceptions, and surfaces suspense balances plus the configuration-gate register. Both are strictly read-only: they post nothing, decide nothing, and delegate every computation to an Action.

**Tech Stack:** Laravel 11, Livewire 3, Pest, PHPStan, MySQL. SYSCOHADA révisé ledger per `docs/specs/02-accounting.md`.

**Spec:** `docs/specs/2026-08-12-accounting-finance-architecture.md` §4, §6, §9.

---

## Before you start

**PHP is not on PATH.** Prefix every command in this plan with:

```powershell
$env:Path="C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64;$env:Path"
```

**Use your own test database.** Other sessions share this repo. Never run against `opeschool_test`:

```powershell
$env:DB_DATABASE="opeschool_test_ar1"
```

Create it once if missing, then `php artisan migrate --force` with that variable set.

**Guard command** — must be green before any task is marked done:

```powershell
php vendor\bin\pest tests\Feature\Accounting tests\Architecture
php vendor\bin\phpstan analyse --memory-limit=1G
```

### The five rules this build must not break

1. **Never `where('status','posted')`.** Always `JournalEntry::scopePostedLedger()`. A statement includes both `posted` **and** `reversed` so a reversal nets its original to zero (`02-accounting.md` §9.3). Filtering on `posted` alone drops the original of every reversed pair and overstates the books. An architecture test enforces this.
2. **Never invent an account code, tax mapping or DSF mapping.** Where configuration is absent the screen reads **Not configured** and names the blocking gate. Task 8 adds a test that fails if any seeder, factory or migration in this build creates one.
3. **Never duplicate a computation.** `Fees\Actions\AgedBalances` and the auxiliary-reconciliation Action already exist. Reuse them.
4. **Read-only.** No Action in this build writes to the ledger. No `save()`, no `create()`, no posting.
5. **Every balance states its axis** (`fiscal_year` | `academic_year`) and its **`as_of`**, both rendered. `BusinessDate::today()`, never `now()`.

---

## File structure

| File | Responsibility |
|---|---|
| `app/Modules/Accounting/Domain/SourceReference.php` | Value object: a resolved (or deliberately inert) link to a source document |
| `app/Modules/Accounting/Actions/Review/ResolveSourceDocument.php` | Maps `source_type` → route + human label. The only place that mapping lives |
| `resources/views/components/accounting/source-link.blade.php` | Renders a `SourceReference` — a link when resolvable, inert labelled text otherwise |
| `app/Modules/Accounting/Domain/ControlStatus.php` | Enum: `Reconciled` \| `Difference` \| `NotConfigured` |
| `app/Modules/Accounting/Domain/ControlCheck.php` | Value object: one control row (key, label, expected, actual, difference, status, blocking gate) |
| `app/Modules/Accounting/Actions/Review/AuxiliaryControlChecks.php` | AR↔GL and AP↔GL identities from collective vs auxiliary balances |
| `app/Modules/Accounting/Actions/Review/ControlAccountChecks.php` | Aggregator: runs every control pair, marks unconfigured ones |
| `app/Modules/Accounting/Actions/Review/JournalExceptions.php` | Journal worklist counts and rows (unposted, draft, manual, forward-posted, missing pièce) |
| `app/Modules/Accounting/Actions/Review/SuspenseBalances.php` | Non-zero suspense/clearing balances with the entries behind them |
| `app/Modules/Accounting/Actions/Review/ConfigurationGates.php` | The §22 gate register and its live configured/unconfigured status |
| `app/Modules/Accounting/Livewire/Review/ControlCentre.php` | The review landing screen |
| `app/Modules/Accounting/Livewire/Review/Journals.php` | Journal exception worklist |
| `app/Modules/Accounting/Livewire/Review/Suspense.php` | Suspense balances + gate register |
| `resources/views/livewire/accounting/review/*.blade.php` | Views for the three screens |
| `tests/Feature/Accounting/Review/*.php` | Feature tests per Action and screen |
| `tests/Architecture/AccountingReviewTest.php` | The guard tests of §9.2 |

---

## Task 1: Enumerate the real source types

The registry in Task 2 must reflect what is genuinely in the ledger. Do not guess these values.

**Files:**
- Create: none (investigation task)

- [ ] **Step 1: List the morph aliases the codebase registers**

Run:

```bash
grep -rn "morphMap\|enforceMorphMap" app/ | head -20
```

- [ ] **Step 2: List the source types actually present in the ledger**

Run:

```powershell
php artisan tinker --execute="dump(App\Modules\Accounting\Models\JournalEntry::query()->select('source_type')->distinct()->pluck('source_type')->all());"
```

- [ ] **Step 3: List the source types any posting rule can emit**

Run:

```bash
grep -rn "source_type" app/Modules/ --include=*.php | grep -v "Models/JournalEntry" | head -30
```

- [ ] **Step 4: Record the findings**

Write the union of steps 1–3 into a scratch note. Each entry needs: the `source_type` string, the model class, the route name that shows that document, and the route parameter. You will paste these into `ResolveSourceDocument::REGISTRY` in Task 2.

Confirm each route name exists:

```bash
php artisan route:list --json | php -r "foreach(json_decode(file_get_contents('php://stdin'),true) as \$r) echo \$r['name'].PHP_EOL;" | sort | grep -E "invoice|receipt|payment|expense|asset|payslip|bill"
```

**A `source_type` with no viewing route is normal.** It renders inert in Task 3. Do not invent a route to fill the gap.

---

## Task 2: `SourceReference` and `ResolveSourceDocument`

**Files:**
- Create: `app/Modules/Accounting/Domain/SourceReference.php`
- Create: `app/Modules/Accounting/Actions/Review/ResolveSourceDocument.php`
- Test: `tests/Feature/Accounting/Review/ResolveSourceDocumentTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Accounting/Review/ResolveSourceDocumentTest.php`:

```php
<?php

declare(strict_types=1);

use App\Modules\Accounting\Actions\Review\ResolveSourceDocument;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

function resolveSourceUser(Role $role = Role::Accountant): User
{
    (new Database\Seeders\RolePermissionSeeder())->run();
    $user = User::factory()->create();
    $user->assignRole($role->value);

    return $user->fresh() ?? $user;
}

it('returns an unlinked reference when the entry has no source document', function () {
    actingAs(resolveSourceUser());

    $reference = app(ResolveSourceDocument::class)->handle(null, null);

    expect($reference->isResolvable())->toBeFalse();
    expect($reference->url())->toBeNull();
    expect($reference->label())->toBe(__('opes.accounting.review.source_none'));
});

it('renders an unmapped source type inertly, never as a raw class name', function () {
    actingAs(resolveSourceUser());

    $reference = app(ResolveSourceDocument::class)->handle('App\\Some\\Unmapped\\Model', 7);

    expect($reference->isResolvable())->toBeFalse();
    expect($reference->url())->toBeNull();
    expect($reference->label())->not->toContain('\\');
    expect($reference->label())->not->toContain('Unmapped');
});

it('never resolves without ledger.view', function () {
    actingAs(resolveSourceUser(Role::Teacher));

    app(ResolveSourceDocument::class)->handle(null, null);
})->throws(Illuminate\Auth\Access\AuthorizationException::class);

it('resolves every source type present in the ledger', function () {
    actingAs(resolveSourceUser());

    $action = app(ResolveSourceDocument::class);

    $types = JournalEntry::query()
        ->whereNotNull('source_type')
        ->select('source_type')
        ->distinct()
        ->pluck('source_type');

    foreach ($types as $type) {
        expect($action->isMapped((string) $type))
            ->toBeTrue("source_type [{$type}] is in the ledger but not in the registry");
    }
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php vendor\bin\pest tests\Feature\Accounting\Review\ResolveSourceDocumentTest.php`
Expected: FAIL — `Class "App\Modules\Accounting\Actions\Review\ResolveSourceDocument" does not exist`

- [ ] **Step 3: Create the value object**

Create `app/Modules/Accounting/Domain/SourceReference.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain;

/**
 * A resolved link from a journal entry to the document that caused it,
 * docs/specs/2026-08-12-accounting-finance-architecture.md §6.
 *
 * An unresolvable reference is a first-class case, not an error: a source
 * type with no viewing route, or an entry with no source at all (a manual
 * journal), is legitimate. It renders as inert labelled text so the chain
 * always terminates visibly rather than in a broken link or a raw class name.
 */
final readonly class SourceReference
{
    private function __construct(
        private string $label,
        private ?string $url,
    ) {}

    public static function linked(string $label, string $url): self
    {
        return new self($label, $url);
    }

    public static function inert(string $label): self
    {
        return new self($label, null);
    }

    public function label(): string
    {
        return $this->label;
    }

    public function url(): ?string
    {
        return $this->url;
    }

    public function isResolvable(): bool
    {
        return $this->url !== null;
    }
}
```

- [ ] **Step 4: Create the Action**

Create `app/Modules/Accounting/Actions/Review/ResolveSourceDocument.php`. Replace the `REGISTRY` entries with the real findings from Task 1 — the two shown are the shape, not a guess to keep:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions\Review;

use App\Modules\Accounting\Domain\SourceReference;
use App\Modules\Identity\Domain\Permission;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route as RouteFacade;

/**
 * Resolves `journal_entries.source_type`/`source_id` to a viewable document,
 * docs/specs/2026-08-12-accounting-finance-architecture.md §6.
 *
 * This is the ONE place the source-type mapping lives. It requires no
 * migration: the columns already exist on the entry.
 *
 * An unmapped type is NOT an error - see SourceReference. What is forbidden
 * is leaking a class name to the operator or emitting a dead link, both of
 * which the tests assert against.
 */
final readonly class ResolveSourceDocument
{
    public const PERMISSION = Permission::LedgerView->value;

    /**
     * source_type => [route name, route parameter name, translation key].
     *
     * Populated from Task 1's enumeration. A type absent here renders inert.
     *
     * @var array<string, array{route: string, param: string, label: string}>
     */
    private const REGISTRY = [
        // 'invoice' => ['route' => 'fees.invoices.show', 'param' => 'invoice', 'label' => 'opes.accounting.review.source_invoice'],
        // 'expense' => ['route' => 'accounting.expenses.show', 'param' => 'expense', 'label' => 'opes.accounting.review.source_expense'],
    ];

    public function handle(?string $sourceType, ?int $sourceId): SourceReference
    {
        Gate::authorize(self::PERMISSION);

        if ($sourceType === null || $sourceId === null) {
            return SourceReference::inert(__('opes.accounting.review.source_none'));
        }

        $entry = self::REGISTRY[$sourceType] ?? null;

        if ($entry === null || ! RouteFacade::has($entry['route'])) {
            return SourceReference::inert(__('opes.accounting.review.source_unmapped'));
        }

        return SourceReference::linked(
            __($entry['label'], ['id' => $sourceId]),
            route($entry['route'], [$entry['param'] => $sourceId]),
        );
    }

    public function isMapped(string $sourceType): bool
    {
        return array_key_exists($sourceType, self::REGISTRY);
    }
}
```

- [ ] **Step 5: Add the translation keys**

In `lang/en/opes.php`, under the `accounting` key, add a `review` block:

```php
'review' => [
    'source_none' => 'No source document',
    'source_unmapped' => 'Source document not viewable',
],
```

In `lang/fr/opes.php`, the same block:

```php
'review' => [
    'source_none' => 'Aucune pièce justificative',
    'source_unmapped' => 'Pièce justificative non consultable',
],
```

`LocalisationTest` requires both files to carry every key. Add each new key to both in the same commit, every time.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php vendor\bin\pest tests\Feature\Accounting\Review\ResolveSourceDocumentTest.php`
Expected: PASS, 4 tests

- [ ] **Step 7: Run the guard**

Run:

```powershell
php vendor\bin\pest tests\Feature\Accounting tests\Architecture tests\Feature\LocalisationTest.php
php vendor\bin\phpstan analyse --memory-limit=1G
```

Expected: as green as when you started.

- [ ] **Step 8: Commit**

```bash
git add app/Modules/Accounting/Domain/SourceReference.php app/Modules/Accounting/Actions/Review/ResolveSourceDocument.php tests/Feature/Accounting/Review/ResolveSourceDocumentTest.php lang/en/opes.php lang/fr/opes.php
git commit -m "feat(accounting): resolve a journal entry to its source document"
```

---

## Task 3: The drill-down Blade component

**Files:**
- Create: `resources/views/components/accounting/source-link.blade.php`
- Test: `tests/Feature/Accounting/Review/SourceLinkComponentTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Accounting/Review/SourceLinkComponentTest.php`:

```php
<?php

declare(strict_types=1);

use App\Modules\Accounting\Domain\SourceReference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;

uses(RefreshDatabase::class);

it('renders a resolvable reference as an anchor', function () {
    $html = Blade::render(
        '<x-accounting.source-link :reference="$reference" />',
        ['reference' => SourceReference::linked('Invoice INV-2026-001', 'https://example.test/invoices/1')],
    );

    expect($html)->toContain('href="https://example.test/invoices/1"');
    expect($html)->toContain('Invoice INV-2026-001');
});

it('renders an inert reference without an anchor', function () {
    $html = Blade::render(
        '<x-accounting.source-link :reference="$reference" />',
        ['reference' => SourceReference::inert('No source document')],
    );

    expect($html)->not->toContain('<a ');
    expect($html)->toContain('No source document');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php vendor\bin\pest tests\Feature\Accounting\Review\SourceLinkComponentTest.php`
Expected: FAIL — unable to locate component `accounting.source-link`

- [ ] **Step 3: Create the component**

Create `resources/views/components/accounting/source-link.blade.php`:

```blade
@props(['reference'])

@if ($reference->isResolvable())
    <a href="{{ $reference->url() }}" class="text-opes-green underline underline-offset-2 hover:no-underline">
        {{ $reference->label() }}
    </a>
@else
    <span class="text-slate-500" title="{{ $reference->label() }}">{{ $reference->label() }}</span>
@endif
```

Before committing, confirm `text-opes-green` is a real utility in this codebase:

```bash
grep -rn "opes-green" resources/css/ tailwind.config.js 2>/dev/null | head -3
```

If it is not, substitute the class the other accounting views use — check `resources/views/livewire/accounting/reports/trial-balance.blade.php` for the established link style and match it.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php vendor\bin\pest tests\Feature\Accounting\Review\SourceLinkComponentTest.php`
Expected: PASS, 2 tests

- [ ] **Step 5: Commit**

```bash
git add resources/views/components/accounting/source-link.blade.php tests/Feature/Accounting/Review/SourceLinkComponentTest.php
git commit -m "feat(accounting): render a source reference as a link or inert text"
```

---

## Task 4: Auxiliary control checks (AR↔GL, AP↔GL)

The identity: for a collective account, the sum of its per-partner (auxiliary) balances must equal the account's own balance. `02-accounting.md` §8 invariant L8 guarantees a line on a collective account always carries a partner, so the two sums are over the same rows and must agree exactly.

**Files:**
- Create: `app/Modules/Accounting/Domain/ControlStatus.php`
- Create: `app/Modules/Accounting/Domain/ControlCheck.php`
- Create: `app/Modules/Accounting/Actions/Review/AuxiliaryControlChecks.php`
- Test: `tests/Feature/Accounting/Review/AuxiliaryControlChecksTest.php`

- [ ] **Step 1: Read the existing auxiliary reconciliation before writing anything**

Run:

```bash
grep -rn "auxiliar\|collective" app/Modules/Accounting/Actions/ --include=*.php -il
```

If an Action already computes auxiliary balances, **call it** rather than writing the query below. Rule 3. Note which file you found and record it in the commit message.

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/Accounting/Review/AuxiliaryControlChecksTest.php`:

```php
<?php

declare(strict_types=1);

use App\Modules\Accounting\Actions\Review\AuxiliaryControlChecks;
use App\Modules\Accounting\Domain\ControlStatus;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

function auxiliaryControlUser(Role $role = Role::Accountant): User
{
    (new Database\Seeders\RolePermissionSeeder())->run();
    $user = User::factory()->create();
    $user->assignRole($role->value);

    return $user->fresh() ?? $user;
}

it('reports every collective account as reconciled on a consistent ledger', function () {
    actingAs(auxiliaryControlUser());

    $checks = app(AuxiliaryControlChecks::class)->handle();

    foreach ($checks as $check) {
        expect($check->status)->toBe(ControlStatus::Reconciled);
        expect($check->difference)->toBe(0);
    }
});

it('states an axis and an as_of on every check', function () {
    actingAs(auxiliaryControlUser());

    $checks = app(AuxiliaryControlChecks::class)->handle();

    foreach ($checks as $check) {
        expect($check->asOf)->not->toBeEmpty();
        expect($check->axis)->toBeIn(['fiscal_year', 'academic_year']);
    }
});

it('refuses without ledger.view', function () {
    actingAs(auxiliaryControlUser(Role::Teacher));

    app(AuxiliaryControlChecks::class)->handle();
})->throws(Illuminate\Auth\Access\AuthorizationException::class);
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `php vendor\bin\pest tests\Feature\Accounting\Review\AuxiliaryControlChecksTest.php`
Expected: FAIL — `AuxiliaryControlChecks` does not exist

- [ ] **Step 4: Create the enum**

Create `app/Modules/Accounting/Domain/ControlStatus.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain;

/**
 * The outcome of one control-account identity,
 * docs/specs/2026-08-12-accounting-finance-architecture.md §4.1.
 *
 * NotConfigured is not a failure. It means a docs/specs/02-accounting.md §22
 * gate blocks the check, and the screen must name that gate rather than
 * render a zero that looks like agreement.
 */
enum ControlStatus: string
{
    case Reconciled = 'reconciled';
    case Difference = 'difference';
    case NotConfigured = 'not_configured';
}
```

- [ ] **Step 5: Create the value object**

Create `app/Modules/Accounting/Domain/ControlCheck.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain;

/**
 * One row of the control matrix,
 * docs/specs/2026-08-12-accounting-finance-architecture.md §4.1.
 *
 * All money is minor units. `difference` is signed and is never clamped:
 * the direction of a break tells the accountant where to look.
 */
final readonly class ControlCheck
{
    public function __construct(
        public string $key,
        public string $label,
        public ?int $expected,
        public ?int $actual,
        public int $difference,
        public ControlStatus $status,
        public string $axis,
        public string $asOf,
        public ?string $blockingGate = null,
    ) {}

    public static function reconciledOrBroken(
        string $key,
        string $label,
        int $expected,
        int $actual,
        string $axis,
        string $asOf,
    ): self {
        $difference = $expected - $actual;

        return new self(
            key: $key,
            label: $label,
            expected: $expected,
            actual: $actual,
            difference: $difference,
            status: $difference === 0 ? ControlStatus::Reconciled : ControlStatus::Difference,
            axis: $axis,
            asOf: $asOf,
        );
    }

    public static function notConfigured(string $key, string $label, string $gate, string $axis, string $asOf): self
    {
        return new self(
            key: $key,
            label: $label,
            expected: null,
            actual: null,
            difference: 0,
            status: ControlStatus::NotConfigured,
            axis: $axis,
            asOf: $asOf,
            blockingGate: $gate,
        );
    }
}
```

- [ ] **Step 6: Create the Action**

Create `app/Modules/Accounting/Actions/Review/AuxiliaryControlChecks.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions\Review;

use App\Modules\Accounting\Domain\ControlCheck;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Identity\Domain\Permission;
use App\Support\Clock\BusinessDate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * AR <-> GL and AP <-> GL, docs/specs/2026-08-12-accounting-finance-architecture.md §4.1.
 *
 * The identity: for every collective account, the sum of its per-partner
 * balances equals the account's own balance. L8 (02-accounting.md §8.3)
 * guarantees a line on a collective account always carries a partner, so
 * both sides sum the same rows and any difference is a real integrity fault.
 *
 * Read path is scopePostedLedger() - both `posted` AND `reversed`, so a
 * reversal nets its original to zero (§9.3). Filtering on `posted` alone
 * would drop the original of every reversed pair.
 *
 * Read-only. This Action decides nothing and writes nothing.
 */
final readonly class AuxiliaryControlChecks
{
    public const PERMISSION = Permission::LedgerView->value;

    /**
     * @return Collection<int, ControlCheck>
     */
    public function handle(?string $asOf = null, string $axis = 'fiscal_year'): Collection
    {
        Gate::authorize(self::PERMISSION);

        $asOf ??= BusinessDate::today();

        return ChartOfAccount::query()
            ->where('is_collective', true)
            ->where('is_archived', false)
            ->orderBy('code')
            ->get()
            ->map(fn (ChartOfAccount $account): ControlCheck => ControlCheck::reconciledOrBroken(
                key: 'auxiliary_'.$account->code,
                label: $account->code.' '.$account->name,
                expected: $this->collectiveBalance($account, $asOf),
                actual: $this->auxiliarySum($account, $asOf),
                axis: $axis,
                asOf: $asOf,
            ))
            ->values();
    }

    private function collectiveBalance(ChartOfAccount $account, string $asOf): int
    {
        return (int) $this->linesUpTo($account, $asOf)->sum(DB::raw('debit - credit'));
    }

    private function auxiliarySum(ChartOfAccount $account, string $asOf): int
    {
        return (int) $this->linesUpTo($account, $asOf)
            ->whereNotNull('journal_entry_lines.partner_id')
            ->sum(DB::raw('debit - credit'));
    }

    /**
     * @return \Illuminate\Database\Query\Builder
     */
    private function linesUpTo(ChartOfAccount $account, string $asOf)
    {
        return DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->whereIn('journal_entries.status', JournalEntry::query()->getModel()::postedLedgerStatuses())
            ->where('journal_entry_lines.account_id', $account->id)
            ->whereDate('journal_entries.date', '<=', $asOf);
    }
}
```

- [ ] **Step 7: Expose the status list the query above needs**

`scopePostedLedger()` is an Eloquent scope, so a raw query cannot call it. Rather than repeat the literal statuses — which the architecture test forbids — add a single accessor beside the scope.

Modify `app/Modules/Accounting/Models/JournalEntry.php`, immediately after `scopePostedLedger()`:

```php
    /**
     * The same two statuses scopePostedLedger() admits, for the raw-query
     * callers that cannot use an Eloquent scope. Both readers must stay in
     * step, so they read from one constant pair - never a repeated literal.
     *
     * @return list<string>
     */
    public static function postedLedgerStatuses(): array
    {
        return [self::STATUS_POSTED, self::STATUS_REVERSED];
    }
```

Then simplify the call in the Action to `JournalEntry::postedLedgerStatuses()`.

Also refactor `scopePostedLedger()` to use it, so there is exactly one definition:

```php
    public function scopePostedLedger(Builder $query): Builder
    {
        return $query->whereIn('status', self::postedLedgerStatuses());
    }
```

- [ ] **Step 8: Check the architecture test still accepts this**

The arch test forbids a literal `'posted'`/`'reversed'` in statement and report files. You have added a raw-query path. Run:

Run: `php vendor\bin\pest tests\Architecture`
Expected: PASS. If it fails because `Actions/Review/` is now caught by the file-pattern rule, that is the rule doing its job — the fix is that the Action calls `postedLedgerStatuses()` and contains no literal, which it does. If the rule matches on filename rather than content, extend its allow-list to `Actions/Review/*` and say why in the commit.

- [ ] **Step 9: Run the tests to verify they pass**

Run: `php vendor\bin\pest tests\Feature\Accounting\Review\AuxiliaryControlChecksTest.php`
Expected: PASS, 3 tests

- [ ] **Step 10: Prove the check actually detects a break**

Add to the test file:

```php
it('reports a difference when an auxiliary line loses its partner', function () {
    actingAs(auxiliaryControlUser());

    $collective = App\Modules\Accounting\Models\ChartOfAccount::query()
        ->where('is_collective', true)
        ->where('is_archived', false)
        ->first();

    if ($collective === null) {
        $this->markTestSkipped('no collective account seeded');
    }

    $line = App\Modules\Accounting\Models\JournalEntryLine::query()
        ->where('account_id', $collective->id)
        ->whereNotNull('partner_id')
        ->first();

    if ($line === null) {
        $this->markTestSkipped('no auxiliary line seeded on a collective account');
    }

    // L8's trigger forbids this through the model, so go around it deliberately
    // to simulate the integrity fault the control is designed to catch.
    DB::statement('SET @opes_disable_l8 = 1');
    DB::table('journal_entry_lines')->where('id', $line->id)->update(['partner_id' => null]);
    DB::statement('SET @opes_disable_l8 = NULL');

    $checks = app(AuxiliaryControlChecks::class)->handle();
    $broken = $checks->firstWhere('key', 'auxiliary_'.$collective->code);

    expect($broken->status)->toBe(ControlStatus::Difference);
    expect($broken->difference)->not->toBe(0);
});
```

**The `@opes_disable_l8` session variable is a guess.** Before running, check how the L8 trigger is written and whether it offers a bypass:

```bash
grep -rn "L8\|partner_id" database/migrations/*230001* | head -20
```

If there is no bypass, drop the trigger for the duration of the test with `DB::unprepared('DROP TRIGGER ...')` inside a `try/finally` that recreates it, or mark this test skipped with a comment explaining that L8 makes the fault unreachable — **which is itself a good finding worth reporting**, because it means the control can only ever break through direct SQL.

- [ ] **Step 11: Commit**

```bash
git add app/Modules/Accounting/Domain/ControlStatus.php app/Modules/Accounting/Domain/ControlCheck.php app/Modules/Accounting/Actions/Review/AuxiliaryControlChecks.php app/Modules/Accounting/Models/JournalEntry.php tests/Feature/Accounting/Review/AuxiliaryControlChecksTest.php
git commit -m "feat(accounting): reconcile every collective account against its auxiliary detail"
```

---

## Task 5: The configuration-gate register

This is what makes rule 2 visible work rather than silent absence.

**Files:**
- Create: `app/Modules/Accounting/Actions/Review/ConfigurationGates.php`
- Test: `tests/Feature/Accounting/Review/ConfigurationGatesTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Accounting/Review/ConfigurationGatesTest.php`:

```php
<?php

declare(strict_types=1);

use App\Modules\Accounting\Actions\Review\ConfigurationGates;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

function gatesUser(Role $role = Role::Accountant): User
{
    (new Database\Seeders\RolePermissionSeeder())->run();
    $user = User::factory()->create();
    $user->assignRole($role->value);

    return $user->fresh() ?? $user;
}

it('lists every gate declared in the accounting spec', function () {
    actingAs(gatesUser());

    $gates = app(ConfigurationGates::class)->handle();

    expect($gates)->toHaveCount(19);
});

it('names the blocked feature for every gate', function () {
    actingAs(gatesUser());

    foreach (app(ConfigurationGates::class)->handle() as $gate) {
        expect($gate['blocks'])->not->toBeEmpty();
        expect($gate['item'])->not->toBeEmpty();
    }
});

it('stays in step with the spec table', function () {
    $spec = file_get_contents(base_path('docs/specs/02-accounting.md'));
    $table = str($spec)->after('## 22. Open items requiring verification')->toString();

    // Every gate number 1..19 appears as a row in the spec's table.
    $rows = preg_match_all('/^\|\s*(\d+)\s*\|/m', $table, $matches);

    expect($rows)->toBe(19);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php vendor\bin\pest tests\Feature\Accounting\Review\ConfigurationGatesTest.php`
Expected: FAIL — `ConfigurationGates` does not exist

- [ ] **Step 3: Create the Action**

Create `app/Modules/Accounting/Actions/Review/ConfigurationGates.php`. Transcribe all 19 rows from `docs/specs/02-accounting.md` §22 — read that table and copy it exactly. The first three are shown to fix the shape:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions\Review;

use App\Modules\Identity\Domain\Permission;
use Illuminate\Support\Facades\Gate;

/**
 * The docs/specs/02-accounting.md §22 verification gates, and whether each
 * is configured yet.
 *
 * Why this exists: §1.1 of the finance architecture spec forbids guessing a
 * statutory value. That rule only works if the gaps are VISIBLE - otherwise
 * "Not configured" reads as an oversight and a future session helpfully
 * fills it in with something plausible. This register makes each gap a named
 * item with a named blocked feature, so it is tracked work.
 *
 * This Action reports. It never configures anything.
 */
final readonly class ConfigurationGates
{
    public const PERMISSION = Permission::LedgerView->value;

    /** @var list<array{number: int, item: string, blocks: string}> */
    private const GATES = [
        ['number' => 1, 'item' => '707x subdivision for boarding / transport / canteen / misc', 'blocks' => 'Fee item to revenue account mapping'],
        ['number' => 2, 'item' => '5-digit tuition extensions under 706', 'blocks' => 'Fee item to revenue account mapping'],
        ['number' => 3, 'item' => '631x subdivision for mobile-money commission', 'blocks' => 'Mobile-money payment method'],
        // ... transcribe rows 4-19 from the spec table verbatim ...
    ];

    /**
     * @return list<array{number: int, item: string, blocks: string, configured: bool}>
     */
    public function handle(): array
    {
        Gate::authorize(self::PERMISSION);

        return array_map(
            fn (array $gate): array => [...$gate, 'configured' => $this->isConfigured($gate['number'])],
            self::GATES,
        );
    }

    /**
     * A gate is configured only when its accounts genuinely exist. There is
     * deliberately no default branch that returns true - an unknown gate is
     * unconfigured, so adding a gate without teaching this method to check it
     * fails safe rather than silently reporting green.
     */
    private function isConfigured(int $number): bool
    {
        return false;
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php vendor\bin\pest tests\Feature\Accounting\Review\ConfigurationGatesTest.php`
Expected: PASS, 3 tests. If the count assertion fails, you have not transcribed all 19 rows.

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Accounting/Actions/Review/ConfigurationGates.php tests/Feature/Accounting/Review/ConfigurationGatesTest.php
git commit -m "feat(accounting): register the statutory configuration gates and what each blocks"
```

---

## Task 6: The Control Centre screen

**Files:**
- Create: `app/Modules/Accounting/Livewire/Review/ControlCentre.php`
- Create: `resources/views/livewire/accounting/review/control-centre.blade.php`
- Modify: `routes/web.php` (beside the other `accounting.*` routes, ~line 677)
- Modify: `app/Modules/Identity/Support/Navigation.php`
- Test: `tests/Feature/Accounting/Review/ControlCentreScreenTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Accounting/Review/ControlCentreScreenTest.php`:

```php
<?php

declare(strict_types=1);

use App\Modules\Accounting\Livewire\Review\ControlCentre;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

function controlCentreUser(Role $role = Role::Accountant): User
{
    (new Database\Seeders\RolePermissionSeeder())->run();
    $user = User::factory()->create();
    $user->assignRole($role->value);

    return $user->fresh() ?? $user;
}

it('renders for an accountant', function () {
    actingAs(controlCentreUser());

    livewire(ControlCentre::class)->assertOk();
});

it('refuses a teacher at the route, not merely in the sidebar', function () {
    actingAs(controlCentreUser(Role::Teacher));

    get('/accounting/review')->assertForbidden();
});

it('states the axis and as_of on the page', function () {
    actingAs(controlCentreUser());

    livewire(ControlCentre::class)
        ->assertSee(__('opes.accounting.review.axis_label'))
        ->assertSee(now()->toDateString());
});

it('shows the gate register count', function () {
    actingAs(controlCentreUser());

    livewire(ControlCentre::class)->assertSee(__('opes.accounting.review.gates_heading'));
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php vendor\bin\pest tests\Feature\Accounting\Review\ControlCentreScreenTest.php`
Expected: FAIL — `ControlCentre` does not exist

- [ ] **Step 3: Create the component**

Create `app/Modules/Accounting/Livewire/Review/ControlCentre.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Livewire\Review;

use App\Modules\Accounting\Actions\Review\AuxiliaryControlChecks;
use App\Modules\Accounting\Actions\Review\ConfigurationGates;
use App\Modules\Identity\Domain\Permission;
use App\Support\Clock\BusinessDate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Illuminate\Support\Facades\Gate;

/**
 * Accounting Review landing screen,
 * docs/specs/2026-08-12-accounting-finance-architecture.md §4.
 *
 * Read-only assurance. Every number comes from an Action; this component
 * filters and presents, exactly as TrialBalance does, and decides nothing.
 *
 * Both the axis and the as_of date are rendered, per §5 - the fiscal and
 * academic answers differ by a full term, and an accountant reading the
 * wrong one misreports to the proprietor.
 */
#[Layout('layouts.app')]
final class ControlCentre extends Component
{
    #[Url]
    public string $axis = 'fiscal_year';

    #[Url]
    public string $asOf = '';

    public function mount(): void
    {
        Gate::authorize(Permission::LedgerView->value);

        if ($this->asOf === '') {
            $this->asOf = BusinessDate::today();
        }
    }

    public function render(): mixed
    {
        return view('livewire.accounting.review.control-centre', [
            'checks' => app(AuxiliaryControlChecks::class)->handle($this->asOf, $this->axis),
            'gates' => app(ConfigurationGates::class)->handle(),
        ]);
    }
}
```

- [ ] **Step 4: Create the view**

Create `resources/views/livewire/accounting/review/control-centre.blade.php`:

```blade
<div class="space-y-6">
    <header class="flex flex-wrap items-baseline justify-between gap-3">
        <h1 class="text-xl font-semibold">{{ __('opes.accounting.review.heading') }}</h1>
        <p class="text-sm text-slate-600">
            {{ __('opes.accounting.review.axis_label') }}:
            <span class="font-medium">{{ __('opes.accounting.review.axis_'.$axis) }}</span>
            &middot;
            {{ __('opes.accounting.review.as_of') }}: <span class="font-medium">{{ $asOf }}</span>
        </p>
    </header>

    <section>
        <h2 class="mb-2 text-lg font-medium">{{ __('opes.accounting.review.controls_heading') }}</h2>
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-slate-600">
                    <th class="py-2">{{ __('opes.accounting.review.control') }}</th>
                    <th class="py-2 text-right">{{ __('opes.accounting.review.difference') }}</th>
                    <th class="py-2">{{ __('opes.accounting.review.status') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($checks as $check)
                    <tr class="border-t border-slate-200">
                        <td class="py-2">{{ $check->label }}</td>
                        <td class="py-2 text-right tabular-nums">{{ number_format($check->difference) }}</td>
                        <td class="py-2">
                            @if ($check->status === App\Modules\Accounting\Domain\ControlStatus::Reconciled)
                                <span class="text-emerald-700">&check; {{ __('opes.accounting.review.reconciled') }}</span>
                            @elseif ($check->status === App\Modules\Accounting\Domain\ControlStatus::Difference)
                                <span class="text-red-700">&#9888; {{ __('opes.accounting.review.difference') }}</span>
                            @else
                                <span class="text-slate-500" title="{{ $check->blockingGate }}">
                                    &mdash; {{ __('opes.accounting.review.not_configured') }}
                                </span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="py-3 text-slate-500">{{ __('opes.accounting.review.no_controls') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <section>
        <h2 class="mb-2 text-lg font-medium">{{ __('opes.accounting.review.gates_heading') }}</h2>
        <p class="mb-2 text-sm text-slate-600">{{ __('opes.accounting.review.gates_explainer') }}</p>
        <ul class="space-y-1 text-sm">
            @foreach ($gates as $gate)
                <li class="flex gap-2 border-t border-slate-200 py-2">
                    <span class="w-8 shrink-0 text-slate-500">{{ $gate['number'] }}</span>
                    <span class="flex-1">{{ $gate['item'] }}</span>
                    <span class="w-64 shrink-0 text-slate-600">{{ $gate['blocks'] }}</span>
                    <span class="w-32 shrink-0 {{ $gate['configured'] ? 'text-emerald-700' : 'text-amber-700' }}">
                        {{ $gate['configured'] ? __('opes.accounting.review.configured') : __('opes.accounting.review.not_configured') }}
                    </span>
                </li>
            @endforeach
        </ul>
    </section>
</div>
```

- [ ] **Step 5: Add the translation keys to both languages**

Add to `lang/en/opes.php` under `accounting.review`:

```php
'heading' => 'Accounting Review',
'axis_label' => 'Axis',
'axis_fiscal_year' => 'Fiscal year',
'axis_academic_year' => 'Academic year',
'as_of' => 'As of',
'controls_heading' => 'Control accounts',
'control' => 'Control',
'difference' => 'Difference',
'status' => 'Status',
'reconciled' => 'Reconciled',
'not_configured' => 'Not configured',
'configured' => 'Configured',
'no_controls' => 'No collective accounts to reconcile.',
'gates_heading' => 'Configuration gates',
'gates_explainer' => 'Statutory values that must be sourced and verified before the features they block can run. An unconfigured gate is tracked work, never a value to guess.',
```

Add the French equivalents to `lang/fr/opes.php`:

```php
'heading' => 'Revue comptable',
'axis_label' => 'Axe',
'axis_fiscal_year' => 'Exercice fiscal',
'axis_academic_year' => 'Année académique',
'as_of' => 'Au',
'controls_heading' => 'Comptes de contrôle',
'control' => 'Contrôle',
'difference' => 'Écart',
'status' => 'Statut',
'reconciled' => 'Rapproché',
'not_configured' => 'Non configuré',
'configured' => 'Configuré',
'no_controls' => 'Aucun compte collectif à rapprocher.',
'gates_heading' => 'Paramètres à valider',
'gates_explainer' => 'Valeurs statutaires à documenter et valider avant que les fonctions qu\'elles bloquent puissent s\'exécuter. Un paramètre non configuré est une tâche suivie, jamais une valeur à deviner.',
```

- [ ] **Step 6: Register the route**

Modify `routes/web.php`, beside the other `accounting.*` routes (near the `accounting.system_documentation` line, ~677):

```php
    Route::get('/accounting/review', \App\Modules\Accounting\Livewire\Review\ControlCentre::class)
        ->middleware('can:ledger.view')->name('accounting.review');
```

- [ ] **Step 7: Add the navigation item**

Modify `app/Modules/Identity/Support/Navigation.php`, beside the other accounting items (after the `reconciliation` entry, ~line 124):

```php
            // 2026-08-12-accounting-finance-architecture.md §4: the integrity
            // surface. Gated identically to the ledger screens - the route
            // refuses on its own, the sidebar merely hides.
            ['key' => 'accounting_review', 'route' => '/accounting/review', 'permission' => Permission::LedgerView, 'enabled' => true, 'built' => true],
```

Add the nav label to both language files under the existing nav key block, matching the key `accounting_review`. Find the block by grepping for an existing key:

```bash
grep -rn "reconciliation" lang/en/opes.php lang/fr/opes.php | head -3
```

- [ ] **Step 8: Run the tests to verify they pass**

Run: `php vendor\bin\pest tests\Feature\Accounting\Review\ControlCentreScreenTest.php`
Expected: PASS, 4 tests

- [ ] **Step 9: Run the full guard, including the navigation contract test**

Run:

```powershell
php vendor\bin\pest tests\Feature\Accounting tests\Architecture tests\Feature\LocalisationTest.php tests\Feature\Ui
php vendor\bin\phpstan analyse --memory-limit=1G
```

Expected: as green as the baseline. `Ui\ShellTest` asserts nav and routes agree by construction — if it fails, your nav entry and route disagree on permission or path.

- [ ] **Step 10: Commit**

```bash
git add app/Modules/Accounting/Livewire/Review/ControlCentre.php resources/views/livewire/accounting/review/control-centre.blade.php routes/web.php app/Modules/Identity/Support/Navigation.php lang/en/opes.php lang/fr/opes.php tests/Feature/Accounting/Review/ControlCentreScreenTest.php
git commit -m "feat(accounting): an integrity screen showing control accounts and open gates"
```

---

## Task 7: Journal exceptions worklist

**Files:**
- Create: `app/Modules/Accounting/Actions/Review/JournalExceptions.php`
- Create: `app/Modules/Accounting/Livewire/Review/Journals.php`
- Create: `resources/views/livewire/accounting/review/journals.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Accounting/Review/JournalExceptionsTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Accounting/Review/JournalExceptionsTest.php`:

```php
<?php

declare(strict_types=1);

use App\Modules\Accounting\Actions\Review\JournalExceptions;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

function journalExceptionsUser(Role $role = Role::Accountant): User
{
    (new Database\Seeders\RolePermissionSeeder())->run();
    $user = User::factory()->create();
    $user->assignRole($role->value);

    return $user->fresh() ?? $user;
}

it('counts a draft entry as unposted', function () {
    actingAs(journalExceptionsUser());

    JournalEntry::factory()->create(['status' => 'draft']);

    $counts = app(JournalExceptions::class)->counts();

    expect($counts['draft'])->toBeGreaterThanOrEqual(1);
});

it('counts an entry with no posting rule as manual', function () {
    actingAs(journalExceptionsUser());

    JournalEntry::factory()->create(['status' => 'posted', 'posting_rule_id' => null]);

    expect(app(JournalExceptions::class)->counts()['manual'])->toBeGreaterThanOrEqual(1);
});

it('counts a forward-posted entry', function () {
    actingAs(journalExceptionsUser());

    JournalEntry::factory()->create(['status' => 'posted', 'is_forward_posted' => true]);

    expect(app(JournalExceptions::class)->counts()['forward_posted'])->toBeGreaterThanOrEqual(1);
});

it('refuses without ledger.view', function () {
    actingAs(journalExceptionsUser(Role::Teacher));

    app(JournalExceptions::class)->counts();
})->throws(Illuminate\Auth\Access\AuthorizationException::class);
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php vendor\bin\pest tests\Feature\Accounting\Review\JournalExceptionsTest.php`
Expected: FAIL — `JournalExceptions` does not exist

- [ ] **Step 3: Create the Action**

Create `app/Modules/Accounting/Actions/Review/JournalExceptions.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions\Review;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Identity\Domain\Permission;
use Illuminate\Support\Facades\Gate;

/**
 * Journal review worklist,
 * docs/specs/2026-08-12-accounting-finance-architecture.md §4.3.
 *
 * Every category here is LEGAL. A manual journal, a backdated entry, a
 * forward-posted entry (02-accounting.md §5.4) are all permitted; they are
 * listed because they are the entries an auditor asks about, not because
 * they are wrong. Nothing here blocks or auto-corrects.
 *
 * Read-only.
 */
final readonly class JournalExceptions
{
    public const PERMISSION = Permission::LedgerView->value;

    /**
     * @return array{draft: int, manual: int, forward_posted: int, reversed: int, missing_piece: int}
     */
    public function counts(): array
    {
        Gate::authorize(self::PERMISSION);

        return [
            'draft' => JournalEntry::query()->where('status', JournalEntry::STATUS_DRAFT)->count(),
            'manual' => JournalEntry::query()->postedLedger()->whereNull('posting_rule_id')->count(),
            'forward_posted' => JournalEntry::query()->postedLedger()->where('is_forward_posted', true)->count(),
            'reversed' => JournalEntry::query()->where('status', JournalEntry::STATUS_REVERSED)->count(),
            'missing_piece' => JournalEntry::query()->postedLedger()->where('attachment_count', 0)->count(),
        ];
    }
}
```

Confirm `STATUS_DRAFT` exists before using it:

```bash
grep -n "const STATUS_" app/Modules/Accounting/Models/JournalEntry.php
```

If the draft constant is named differently, use the real name.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php vendor\bin\pest tests\Feature\Accounting\Review\JournalExceptionsTest.php`
Expected: PASS, 4 tests

- [ ] **Step 5: Create the screen**

Create `app/Modules/Accounting/Livewire/Review/Journals.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Livewire\Review;

use App\Modules\Accounting\Actions\Review\JournalExceptions;
use App\Modules\Accounting\Actions\Review\ResolveSourceDocument;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Identity\Domain\Permission;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\Gate;

/**
 * Journal exception worklist,
 * docs/specs/2026-08-12-accounting-finance-architecture.md §4.3.
 *
 * Each row drills to its source document through ResolveSourceDocument (§6),
 * so an auditor's "why does this entry exist?" is one click, not a query.
 */
#[Layout('layouts.app')]
final class Journals extends Component
{
    use WithPagination;

    #[Url]
    public string $filter = 'draft';

    public function mount(): void
    {
        Gate::authorize(Permission::LedgerView->value);
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function render(): mixed
    {
        $resolver = app(ResolveSourceDocument::class);

        $query = match ($this->filter) {
            'manual' => JournalEntry::query()->postedLedger()->whereNull('posting_rule_id'),
            'forward_posted' => JournalEntry::query()->postedLedger()->where('is_forward_posted', true),
            'reversed' => JournalEntry::query()->where('status', JournalEntry::STATUS_REVERSED),
            'missing_piece' => JournalEntry::query()->postedLedger()->where('attachment_count', 0),
            default => JournalEntry::query()->where('status', JournalEntry::STATUS_DRAFT),
        };

        $entries = $query->orderByDesc('date')->paginate(25);

        return view('livewire.accounting.review.journals', [
            'entries' => $entries,
            'counts' => app(JournalExceptions::class)->counts(),
            'references' => $entries->getCollection()->mapWithKeys(
                fn (JournalEntry $entry): array => [
                    $entry->id => $resolver->handle($entry->source_type, $entry->source_id),
                ],
            ),
        ]);
    }
}
```

- [ ] **Step 6: Create the view**

Create `resources/views/livewire/accounting/review/journals.blade.php`:

```blade
<div class="space-y-4">
    <h1 class="text-xl font-semibold">{{ __('opes.accounting.review.journals_heading') }}</h1>

    <nav class="flex flex-wrap gap-2 text-sm">
        @foreach (['draft', 'manual', 'forward_posted', 'reversed', 'missing_piece'] as $key)
            <button
                type="button"
                wire:click="$set('filter', '{{ $key }}')"
                class="rounded-full border px-3 py-1 {{ $filter === $key ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-300' }}"
            >
                {{ __('opes.accounting.review.journal_'.$key) }}
                <span class="ml-1 tabular-nums">{{ $counts[$key] }}</span>
            </button>
        @endforeach
    </nav>

    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-slate-600">
                <th class="py-2">{{ __('opes.accounting.review.date') }}</th>
                <th class="py-2">{{ __('opes.accounting.review.piece') }}</th>
                <th class="py-2">{{ __('opes.accounting.review.label') }}</th>
                <th class="py-2 text-right">{{ __('opes.accounting.review.amount') }}</th>
                <th class="py-2">{{ __('opes.accounting.review.source') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($entries as $entry)
                <tr class="border-t border-slate-200">
                    <td class="py-2">{{ $entry->date->toDateString() }}</td>
                    <td class="py-2">{{ $entry->piece_no ?? '—' }}</td>
                    <td class="py-2">{{ $entry->label }}</td>
                    <td class="py-2 text-right tabular-nums">{{ number_format($entry->total_debit) }}</td>
                    <td class="py-2"><x-accounting.source-link :reference="$references[$entry->id]" /></td>
                </tr>
            @empty
                <tr><td colspan="5" class="py-3 text-slate-500">{{ __('opes.accounting.review.no_entries') }}</td></tr>
            @endforelse
        </tbody>
    </table>

    {{ $entries->links() }}
</div>
```

- [ ] **Step 7: Add the translation keys to both language files**

`lang/en/opes.php`, under `accounting.review`:

```php
'journals_heading' => 'Journal review',
'journal_draft' => 'Draft',
'journal_manual' => 'Manual',
'journal_forward_posted' => 'Forward-posted',
'journal_reversed' => 'Reversed',
'journal_missing_piece' => 'Missing document',
'date' => 'Date',
'piece' => 'Piece no.',
'label' => 'Label',
'amount' => 'Amount',
'source' => 'Source',
'no_entries' => 'No entries in this category.',
```

`lang/fr/opes.php`:

```php
'journals_heading' => 'Revue des journaux',
'journal_draft' => 'Brouillon',
'journal_manual' => 'Manuelle',
'journal_forward_posted' => 'Reportée',
'journal_reversed' => 'Contrepassée',
'journal_missing_piece' => 'Pièce manquante',
'date' => 'Date',
'piece' => 'N° de pièce',
'label' => 'Libellé',
'amount' => 'Montant',
'source' => 'Source',
'no_entries' => 'Aucune écriture dans cette catégorie.',
```

- [ ] **Step 8: Register the route**

Modify `routes/web.php`, beside `accounting.review`:

```php
    Route::get('/accounting/review/journals', \App\Modules\Accounting\Livewire\Review\Journals::class)
        ->middleware('can:ledger.view')->name('accounting.review.journals');
```

- [ ] **Step 9: Run the guard**

Run:

```powershell
php vendor\bin\pest tests\Feature\Accounting tests\Architecture tests\Feature\LocalisationTest.php tests\Feature\Ui
php vendor\bin\phpstan analyse --memory-limit=1G
```

Expected: green.

- [ ] **Step 10: Commit**

```bash
git add app/Modules/Accounting/Actions/Review/JournalExceptions.php app/Modules/Accounting/Livewire/Review/Journals.php resources/views/livewire/accounting/review/journals.blade.php routes/web.php lang/en/opes.php lang/fr/opes.php tests/Feature/Accounting/Review/JournalExceptionsTest.php
git commit -m "feat(accounting): a journal worklist that drills to each entry's source"
```

---

## Task 8: The full control matrix and suspense balances

Task 4 built the two auxiliary identities. Spec §4.1 lists nine. The other seven each depend on another module's schema, which this plan has **not** verified — so this task investigates first and reports honestly rather than computing something plausible.

**Files:**
- Create: `app/Modules/Accounting/Actions/Review/ControlAccountChecks.php`
- Create: `app/Modules/Accounting/Actions/Review/SuspenseBalances.php`
- Modify: `app/Modules/Accounting/Livewire/Review/ControlCentre.php`
- Test: `tests/Feature/Accounting/Review/ControlAccountChecksTest.php`

- [ ] **Step 1: Investigate what each remaining control can actually be computed from**

For each of bank, cash, electronic money, payroll, fixed assets, inventory and tax, find whether a balance source exists:

```bash
grep -rn "class BankStatement\b" app/Modules/Accounting/Models/BankStatement.php
grep -rln "reconciled_balance\|statement_balance" app/Modules/Accounting/
grep -rln "NetBookValue\|net_book_value\|depreciation" app/Modules/Assets/Actions/ | head -5
grep -rln "liability\|payable" app/Modules/Payroll/Actions/ | head -5
grep -rln "valuation\|stock_value" app/Modules/Inventory/ | head -5
```

Record, for each of the seven: **either** the Action/column that yields the subledger figure, **or** "no source — mark NotConfigured". Both answers are acceptable. Inventing a query is not.

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/Accounting/Review/ControlAccountChecksTest.php`:

```php
<?php

declare(strict_types=1);

use App\Modules\Accounting\Actions\Review\ControlAccountChecks;
use App\Modules\Accounting\Domain\ControlStatus;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

function controlChecksUser(Role $role = Role::Accountant): User
{
    (new Database\Seeders\RolePermissionSeeder())->run();
    $user = User::factory()->create();
    $user->assignRole($role->value);

    return $user->fresh() ?? $user;
}

it('returns a row for every control named in the spec', function () {
    actingAs(controlChecksUser());

    $keys = app(ControlAccountChecks::class)->handle()->pluck('key');

    foreach (['bank', 'cash', 'electronic_money', 'payroll', 'fixed_assets', 'inventory', 'tax'] as $expected) {
        expect($keys)->toContain($expected);
    }
});

it('never reports a control as reconciled when it has no source', function () {
    actingAs(controlChecksUser());

    foreach (app(ControlAccountChecks::class)->handle() as $check) {
        if ($check->status === ControlStatus::NotConfigured) {
            expect($check->blockingGate)->not->toBeEmpty();
            expect($check->expected)->toBeNull();
            expect($check->actual)->toBeNull();
        }
    }
});

it('refuses without ledger.view', function () {
    actingAs(controlChecksUser(Role::Teacher));

    app(ControlAccountChecks::class)->handle();
})->throws(Illuminate\Auth\Access\AuthorizationException::class);
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `php vendor\bin\pest tests\Feature\Accounting\Review\ControlAccountChecksTest.php`
Expected: FAIL — `ControlAccountChecks` does not exist

- [ ] **Step 4: Create the aggregator**

Create `app/Modules/Accounting/Actions/Review/ControlAccountChecks.php`. Replace each `notConfigured` row with a real computation **only** where Step 1 found a genuine source:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions\Review;

use App\Modules\Accounting\Domain\ControlCheck;
use App\Modules\Identity\Domain\Permission;
use App\Support\Clock\BusinessDate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * The full control matrix,
 * docs/specs/2026-08-12-accounting-finance-architecture.md §4.1.
 *
 * A control with no verified subledger source reports NotConfigured and says
 * why. It does NOT report zero. A zero difference is a statement that the
 * books agree; emitting one from an uncomputed control would be the exact
 * false assurance this whole subsystem exists to prevent.
 */
final readonly class ControlAccountChecks
{
    public const PERMISSION = Permission::LedgerView->value;

    /**
     * @return Collection<int, ControlCheck>
     */
    public function handle(?string $asOf = null, string $axis = 'fiscal_year'): Collection
    {
        Gate::authorize(self::PERMISSION);

        $asOf ??= BusinessDate::today();

        $auxiliary = app(AuxiliaryControlChecks::class)->handle($asOf, $axis);

        $pending = collect(['bank', 'cash', 'electronic_money', 'payroll', 'fixed_assets', 'inventory', 'tax'])
            ->map(fn (string $key): ControlCheck => ControlCheck::notConfigured(
                key: $key,
                label: __('opes.accounting.review.control_'.$key),
                gate: __('opes.accounting.review.control_source_pending'),
                axis: $axis,
                asOf: $asOf,
            ));

        return $auxiliary->concat($pending)->values();
    }
}
```

- [ ] **Step 5: Add the translation keys to both language files**

`lang/en/opes.php`, under `accounting.review`:

```php
'control_bank' => 'Bank ↔ GL',
'control_cash' => 'Cash ↔ GL',
'control_electronic_money' => 'Electronic money ↔ GL',
'control_payroll' => 'Payroll ↔ GL',
'control_fixed_assets' => 'Fixed assets ↔ GL',
'control_inventory' => 'Inventory ↔ GL',
'control_tax' => 'Tax ↔ GL',
'control_source_pending' => 'Subledger source not yet wired for this control.',
'suspense_heading' => 'Suspense and clearing balances',
'suspense_empty' => 'No suspense or clearing account carries a balance.',
```

`lang/fr/opes.php`:

```php
'control_bank' => 'Banque ↔ GL',
'control_cash' => 'Caisse ↔ GL',
'control_electronic_money' => 'Monnaie électronique ↔ GL',
'control_payroll' => 'Paie ↔ GL',
'control_fixed_assets' => 'Immobilisations ↔ GL',
'control_inventory' => 'Stocks ↔ GL',
'control_tax' => 'Fiscalité ↔ GL',
'control_source_pending' => 'Source auxiliaire non encore raccordée pour ce contrôle.',
'suspense_heading' => 'Comptes d\'attente et de passage',
'suspense_empty' => 'Aucun compte d\'attente ne présente de solde.',
```

- [ ] **Step 6: Create the suspense Action**

Create `app/Modules/Accounting/Actions/Review/SuspenseBalances.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions\Review;

use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Identity\Domain\Permission;
use App\Support\Clock\BusinessDate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Non-zero suspense and clearing balances,
 * docs/specs/2026-08-12-accounting-finance-architecture.md §4.4.
 *
 * A suspense account should be zero outside a migration window. A balance
 * here is a standing exception that needs an owner, not a rounding artifact.
 *
 * Read-only.
 */
final readonly class SuspenseBalances
{
    public const PERMISSION = Permission::LedgerView->value;

    /**
     * @return Collection<int, object{code: string, name: string, balance: int}>
     */
    public function handle(?string $asOf = null): Collection
    {
        Gate::authorize(self::PERMISSION);

        $asOf ??= BusinessDate::today();

        return ChartOfAccount::query()
            ->where('is_archived', false)
            ->where(fn ($q) => $q->where('code', 'like', '47%')->orWhere('name', 'like', '%attente%'))
            ->orderBy('code')
            ->get()
            ->map(function (ChartOfAccount $account) use ($asOf): object {
                $balance = (int) DB::table('journal_entry_lines')
                    ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
                    ->whereIn('journal_entries.status', JournalEntry::postedLedgerStatuses())
                    ->where('journal_entry_lines.account_id', $account->id)
                    ->whereDate('journal_entries.date', '<=', $asOf)
                    ->sum(DB::raw('debit - credit'));

                return (object) ['code' => $account->code, 'name' => $account->name, 'balance' => $balance];
            })
            ->filter(fn (object $row): bool => $row->balance !== 0)
            ->values();
    }
}
```

**The `47%` prefix and the `attente` label match are a heuristic, not a statutory claim.** Before committing, confirm how this chart marks suspense accounts:

```bash
grep -rn "attente\|suspense\|47" database/seeders/*ChartOfAccount* app/Modules/Accounting/Domain/AccountType.php | head -10
```

If `ChartOfAccount` carries an explicit flag or `AccountType` case for suspense, use it and delete the heuristic. A flag is authoritative; a code prefix is a guess, and §1.1 applies.

- [ ] **Step 7: Wire both into the Control Centre**

Modify `app/Modules/Accounting/Livewire/Review/ControlCentre.php` — swap the Action and add suspense:

```php
        return view('livewire.accounting.review.control-centre', [
            'checks' => app(ControlAccountChecks::class)->handle($this->asOf, $this->axis),
            'gates' => app(ConfigurationGates::class)->handle(),
            'suspense' => app(SuspenseBalances::class)->handle($this->asOf),
        ]);
```

Update the `use` statements accordingly, replacing `AuxiliaryControlChecks` with `ControlAccountChecks` and adding `SuspenseBalances`.

Add a section to `resources/views/livewire/accounting/review/control-centre.blade.php`, before the gates section:

```blade
    <section>
        <h2 class="mb-2 text-lg font-medium">{{ __('opes.accounting.review.suspense_heading') }}</h2>
        @forelse ($suspense as $row)
            <div class="flex justify-between border-t border-slate-200 py-2 text-sm">
                <span>{{ $row->code }} {{ $row->name }}</span>
                <span class="tabular-nums text-amber-700">{{ number_format($row->balance) }}</span>
            </div>
        @empty
            <p class="text-sm text-slate-500">{{ __('opes.accounting.review.suspense_empty') }}</p>
        @endforelse
    </section>
```

- [ ] **Step 8: Run the tests**

Run:

```powershell
php vendor\bin\pest tests\Feature\Accounting\Review tests\Feature\LocalisationTest.php
```

Expected: PASS, including the earlier Control Centre tests which must still be green.

- [ ] **Step 9: Commit**

```bash
git add app/Modules/Accounting/Actions/Review/ControlAccountChecks.php app/Modules/Accounting/Actions/Review/SuspenseBalances.php app/Modules/Accounting/Livewire/Review/ControlCentre.php resources/views/livewire/accounting/review/control-centre.blade.php lang/en/opes.php lang/fr/opes.php tests/Feature/Accounting/Review/ControlAccountChecksTest.php
git commit -m "feat(accounting): the full control matrix, with unwired controls named not configured"
```

---

## Task 9: The guard tests

These exist to stop a *future* session from undoing the build's discipline. They are the most valuable tests here.

**Files:**
- Create: `tests/Architecture/AccountingReviewTest.php`

- [ ] **Step 1: Write the tests**

Create `tests/Architecture/AccountingReviewTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

it('never invents a statutory account code, tax mapping or DSF mapping', function () {
    $paths = array_merge(
        File::allFiles(base_path('app/Modules/Accounting/Actions/Review')),
        File::allFiles(base_path('app/Modules/Accounting/Livewire/Review')),
    );

    foreach ($paths as $file) {
        $contents = $file->getContents();

        expect($contents)->not->toMatch('/dsf_line_code\s*=>/i',
            "{$file->getFilename()} assigns a DSF mapping. Rule 2: gates are sourced, never guessed.");
        expect($contents)->not->toMatch('/ChartOfAccount::(create|firstOrCreate|updateOrCreate)/',
            "{$file->getFilename()} creates an account. The review layer is read-only.");
    }
});

it('keeps the review layer read-only', function () {
    $paths = File::allFiles(base_path('app/Modules/Accounting/Actions/Review'));

    foreach ($paths as $file) {
        $contents = $file->getContents();

        foreach (['->save()', '->delete()', '->update(', 'DB::insert(', 'DB::statement('] as $forbidden) {
            expect($contents)->not->toContain($forbidden,
                "{$file->getFilename()} contains [{$forbidden}]. The review layer reports; it never writes.");
        }
    }
});

it('never filters the ledger on a bare posted status', function () {
    $paths = array_merge(
        File::allFiles(base_path('app/Modules/Accounting/Actions/Review')),
        File::allFiles(base_path('app/Modules/Accounting/Livewire/Review')),
    );

    foreach ($paths as $file) {
        $contents = $file->getContents();

        expect($contents)->not->toMatch("/where\(\s*'status'\s*,\s*'posted'\s*\)/",
            "{$file->getFilename()} filters on 'posted' alone, dropping the original of every reversed pair. Use postedLedger().");
    }
});

it('does not reintroduce the rejected Anglo-American account codes', function () {
    $rejected = ['101100', '401100', '401200', '411100'];

    $paths = array_merge(
        File::allFiles(base_path('app/Modules/Accounting')),
        File::allFiles(base_path('database/seeders')),
    );

    foreach ($paths as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        foreach ($rejected as $code) {
            expect($file->getContents())->not->toContain("'{$code}'",
                "{$file->getFilename()} contains rejected code {$code}. See 2026-08-12-accounting-finance-architecture.md §1.2.");
        }
    }
});
```

- [ ] **Step 2: Run them**

Run: `php vendor\bin\pest tests\Architecture\AccountingReviewTest.php`
Expected: PASS, 4 tests.

If the rejected-codes test fails on a **pre-existing** file, do not silently change that file. Report it — a rejected code already in the seeder is exactly the bug §1.2 was written to catch, and it needs the spec owner's decision.

- [ ] **Step 3: Commit**

```bash
git add tests/Architecture/AccountingReviewTest.php
git commit -m "test(accounting): guard the review layer's read-only and no-guessed-config rules"
```

---

## Task 10: Full verification

- [ ] **Step 1: Run the complete accounting and architecture suites**

```powershell
$env:DB_DATABASE="opeschool_test_ar1"
php vendor\bin\pest tests\Feature\Accounting tests\Architecture tests\Feature\LocalisationTest.php tests\Feature\Ui
php vendor\bin\phpstan analyse --memory-limit=1G
```

- [ ] **Step 2: Compare against the baseline**

Record the pass/fail counts. The suite must be **at least as green** as when you started. Any newly failing test is yours; investigate before proceeding.

- [ ] **Step 3: Walk the screens by hand**

Start the app and visit `/accounting/review` and `/accounting/review/journals` as an accountant. Confirm:
- the axis and `as_of` are both visible
- unconfigured gates read "Not configured" and name what they block
- a source link either navigates or is visibly inert — never a dead link or a class name

- [ ] **Step 4: Update the spec's audit table**

Modify `docs/specs/2026-08-12-accounting-finance-architecture.md` §2.1: the Accounting Review row moves from MISSING to BUILT, and §10's slices 1 and 2 are marked done with the date.

- [ ] **Step 5: Commit**

```bash
git add docs/specs/2026-08-12-accounting-finance-architecture.md
git commit -m "docs(accounting): record slices 1 and 2 as delivered"
```

---

## What this plan deliberately does not do

- **It does not close any of the 19 configuration gates.** Task 5 makes them visible and names what each blocks. Closing them requires a qualified accountant sourcing the values, not an engineer choosing plausible ones.
- **It does not build the Finance Control Centre dashboard** (spec §5) — that is slice 3 and its own plan, and it depends on this one.
- **It does not touch AR/AP screens** (slice 4), analytical review (slice 5), navigation grouping or the documentation page (slice 6), or recurring journals (slice 7).
- **It writes nothing to the ledger.** If a task seems to need a write, the task is wrong — stop and ask.
