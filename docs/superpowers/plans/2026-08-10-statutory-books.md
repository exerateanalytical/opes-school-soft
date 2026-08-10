# Statutory Books (AUDCIF Art. 19) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the four mandatory OHADA accounting books — livre-journal, grand livre, balance générale, livre d'inventaire — as signed, hashed, immutable legal registers rather than reports.

**Architecture:** One `statutory_books` table records each generation as an immutable row carrying the PDF's sha256 and a detached signature. A regeneration never overwrites: it inserts a new row whose `supersedes_book_id` points at the old one, so the version chain is itself auditable. Four content builders (one per book type) read exclusively through `JournalEntry::scopePostedLedger()` so `posted` and `reversed` both appear and a reversal nets to zero — the L13 rule that makes these books legally correct. A single `GenerateStatutoryBook` Action renders, hashes, signs and records.

**Tech Stack:** Laravel 13, Livewire 4, MySQL 8.4, barryvdh/laravel-dompdf, PhpSpreadsheet, Pest.

**Spec:** `docs/specs/02-accounting.md` §14 (entity §14.1, contents §14.2, cote et paraphe §14.3).

---

## Critical context for the implementer

**Read these before starting. They are the traps this codebase has already been bitten by.**

1. **NEVER write `where('status', 'posted')`.** Use `JournalEntry::query()->postedLedger()`. That scope is `whereIn('status', ['posted','reversed'])`. Excluding `reversed` drops the original half of a reversal pair while keeping the reversal, which silently flips the sign of the whole transaction and still balances — so nothing catches it. There is an architecture test forbidding the literal string; more importantly, a book built the wrong way is legally wrong.

2. **Money is BIGINT minor units (FCFA), integer arithmetic only.** Use `App\Support\Money\Money`. No floats anywhere.

3. **A statutory book is never regenerated in place.** `supersedes_book_id` + a new row. Nothing is UPDATEd, nothing is DELETEd.

4. **Three-part wiring is mandatory** for any screen: route in `routes/web.php` + `Livewire::component()` alias in `AppServiceProvider` + nav entry in `Navigation.php`, plus `lang/en` and `lang/fr` keys and a nav icon. A component whose alias is missing renders fine under `Livewire::test()` and answers **500** in a browser — that is exactly how five Tax screens shipped broken. `tests/Feature/Shell/RouteSmokeTest.php` is the guard.

5. **Blade must have a single root element** or Livewire throws.

6. **Never run `migrate:fresh`, `migrate:refresh`, `migrate:reset` or `db:wipe` against the `opeschool` database.** It holds the demo data for a live presentation. Tests run with `DB_DATABASE=opeschool_test`.

7. **PHP is `/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe`** — the system `php` is not the toolchain.

---

## File Structure

| File | Responsibility |
|---|---|
| `database/migrations/2026_08_10_410001_create_statutory_books_table.php` | The register table |
| `app/Modules/Accounting/Domain/StatutoryBookType.php` | The four book types + their labels |
| `app/Modules/Accounting/Models/StatutoryBook.php` | Eloquent model, immutable by convention |
| `app/Modules/Accounting/Actions/Books/BuildLivreJournal.php` | Chronological entry register |
| `app/Modules/Accounting/Actions/Books/BuildGrandLivre.php` | Per-account movements + running balance |
| `app/Modules/Accounting/Actions/Books/BuildBalanceGenerale.php` | Per-account opening/movement/closing with level totals |
| `app/Modules/Accounting/Actions/Books/BuildLivreInventaire.php` | Bilan + résultat + flux + physical inventory transcription |
| `app/Modules/Accounting/Actions/Books/GenerateStatutoryBook.php` | Render → hash → sign → record → supersede |
| `app/Modules/Accounting/Livewire/Books/Index.php` | The screen |
| `resources/views/livewire/accounting/books/index.blade.php` | Its view |
| `resources/views/reports/statutory-book.blade.php` | Paginated PDF shell with cote-et-paraphe cover |
| `tests/Feature/Accounting/StatutoryBookTest.php` | Behaviour |

---

### Task 1: The register table and model

**Files:**
- Create: `database/migrations/2026_08_10_410001_create_statutory_books_table.php`
- Create: `app/Modules/Accounting/Domain/StatutoryBookType.php`
- Create: `app/Modules/Accounting/Models/StatutoryBook.php`

- [ ] **Step 1: Create the book-type enum**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain;

/**
 * The four books AUDCIF Art. 19 makes mandatory. These are legal registers,
 * not reports: once generated they are immutable, and a correction produces a
 * NEW book that supersedes its predecessor.
 */
enum StatutoryBookType: string
{
    case LivreJournal = 'livre_journal';
    case GrandLivre = 'grand_livre';
    case BalanceGenerale = 'balance_generale';
    case LivreInventaire = 'livre_inventaire';

    public function label(): string
    {
        return match ($this) {
            self::LivreJournal => 'Livre-journal',
            self::GrandLivre => 'Grand livre',
            self::BalanceGenerale => 'Balance generale',
            self::LivreInventaire => "Livre d'inventaire",
        };
    }

    /**
     * The livre d'inventaire is generated once per fiscal year at close,
     * after the year-end sequence completes (§14.2). The other three may be
     * generated for any period within a year.
     */
    public function isAnnualOnly(): bool
    {
        return $this === self::LivreInventaire;
    }
}
```

- [ ] **Step 2: Write the migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/02-accounting.md §14.1 - `StatutoryBook`.
 *
 * v1 omitted the livre d'inventaire entirely and treated the other three as
 * reports. They are not reports, they are legal registers: signed, paginated,
 * immutable once written, and never regenerated in place.
 *
 * `supersedes_book_id` is the whole point of the design. A book generated
 * before a correction is not deleted and not overwritten - the regenerated
 * book points BACK at it, so the sequence of versions is itself auditable.
 * RESTRICT on that FK means the superseded row can never be pulled out from
 * under its successor.
 *
 * Retention (§15, AUDCIF Art. 24) forbids hard-deleting any of this for ten
 * years, which is why there is no SoftDeletes and no cascade anywhere here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statutory_books', function (Blueprint $table): void {
            $table->id();

            $table->string('book_type', 24);
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years')->restrictOnDelete();

            $table->date('period_start');
            $table->date('period_end');

            $table->dateTime('generated_at');
            $table->foreignId('generated_by')->constrained('users')->restrictOnDelete();

            $table->integer('page_count')->default(0);
            $table->string('first_piece_no', 40)->nullable();
            $table->string('last_piece_no', 40)->nullable();

            // Money is BIGINT minor units, signed (00-core §5).
            $table->bigInteger('total_debit')->default(0);
            $table->bigInteger('total_credit')->default(0);
            $table->integer('entry_count')->default(0);
            $table->integer('line_count')->default(0);

            $table->string('file_path', 500);
            $table->char('sha256', 64);
            $table->text('signature')->nullable();

            $table->foreignId('supersedes_book_id')->nullable()
                ->constrained('statutory_books')->restrictOnDelete();

            $table->boolean('is_definitive')->default(false);

            $table->timestamps();

            $table->unique(
                ['book_type', 'fiscal_year_id', 'period_start', 'period_end', 'generated_at'],
                'uq_statutory_books_generation'
            );

            $table->index(['fiscal_year_id', 'book_type'], 'ix_statutory_books_year_type');
        });

        DB::statement(
            "ALTER TABLE statutory_books ADD CONSTRAINT ck_statutory_books_type "
            ."CHECK (book_type IN ('livre_journal','grand_livre','balance_generale','livre_inventaire'))"
        );

        DB::statement(
            'ALTER TABLE statutory_books ADD CONSTRAINT ck_statutory_books_period '
            .'CHECK (period_end >= period_start)'
        );

        // A book may not supersede itself.
        DB::statement(
            'ALTER TABLE statutory_books ADD CONSTRAINT ck_statutory_books_supersede_self '
            .'CHECK (supersedes_book_id IS NULL OR supersedes_book_id <> id)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('statutory_books');
    }
};
```

- [ ] **Step 3: Create the model**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Modules\Accounting\Domain\StatutoryBookType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property StatutoryBookType $book_type
 * @property int $fiscal_year_id
 * @property string $sha256
 * @property int|null $supersedes_book_id
 * @property bool $is_definitive
 */
final class StatutoryBook extends Model
{
    protected $table = 'statutory_books';

    /** @var list<string> */
    protected $fillable = [
        'book_type', 'fiscal_year_id', 'period_start', 'period_end',
        'generated_at', 'generated_by', 'page_count',
        'first_piece_no', 'last_piece_no',
        'total_debit', 'total_credit', 'entry_count', 'line_count',
        'file_path', 'sha256', 'signature',
        'supersedes_book_id', 'is_definitive',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'book_type' => StatutoryBookType::class,
            'period_start' => 'date',
            'period_end' => 'date',
            'generated_at' => 'datetime',
            'total_debit' => 'integer',
            'total_credit' => 'integer',
            'entry_count' => 'integer',
            'line_count' => 'integer',
            'page_count' => 'integer',
            'is_definitive' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_book_id');
    }
}
```

- [ ] **Step 4: Run the migration**

Run:
```bash
cd /c/laragon/www/opeschool-cloud && /c/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe artisan migrate --force
```
Expected: `2026_08_10_410001_create_statutory_books_table .. DONE`

- [ ] **Step 5: Commit**

```bash
git add database/migrations app/Modules/Accounting/Domain/StatutoryBookType.php app/Modules/Accounting/Models/StatutoryBook.php
git commit -m "feat(accounting): statutory_books register table and model (AUDCIF Art. 19)"
```

---

### Task 2: Livre-journal content builder

**Files:**
- Create: `app/Modules/Accounting/Actions/Books/BuildLivreJournal.php`
- Test: `tests/Feature/Accounting/StatutoryBookTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Modules\Accounting\Actions\Books\BuildLivreJournal;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lists posted and reversed entries in chronological order, excluding drafts', function (): void {
    [$fiscalYear, $posted, $reversed, $draft] = bookFixture();

    $rows = app(BuildLivreJournal::class)->handle(
        (int) $fiscalYear->id,
        '2026-01-01',
        '2026-12-31',
    );

    $pieces = array_values(array_unique(array_column($rows, 'piece_no')));

    expect($pieces)->toContain($posted->piece_no)
        ->and($pieces)->toContain($reversed->piece_no)
        ->and($pieces)->not->toContain($draft->piece_no);
});
```

- [ ] **Step 2: Run it and confirm it fails**

Run:
```bash
cd /c/laragon/www/opeschool-cloud && DB_DATABASE=opeschool_test /c/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe artisan test --filter=StatutoryBookTest
```
Expected: FAIL — `Class "App\Modules\Accounting\Actions\Books\BuildLivreJournal" not found`

- [ ] **Step 3: Implement the builder**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions\Books;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Identity\Domain\Permission;
use Illuminate\Support\Facades\Gate;

/**
 * §14.2 - the livre-journal: every entry in chronological order by `date`
 * then `piece_no`, with journal, piece, dates, label, account, partner,
 * debit and credit.
 *
 * Reads through `postedLedger()`, which is `posted` + `reversed` (L13). That
 * is not an oversight: a reversal exists to CANCEL its original by netting to
 * zero in the register, never by making the original vanish. Filtering to
 * `posted` alone would drop the original half of the pair, keep the reversal,
 * and silently flip the sign of the transaction while still balancing.
 */
final class BuildLivreJournal
{
    /**
     * @return list<array<string, mixed>>
     */
    public function handle(int $fiscalYearId, string $periodStart, string $periodEnd): array
    {
        Gate::authorize(Permission::LedgerView->value);

        $rows = JournalEntry::query()
            ->postedLedger()
            ->where('journal_entries.fiscal_year_id', $fiscalYearId)
            ->whereBetween('journal_entries.date', [$periodStart, $periodEnd])
            ->join('journal_entry_lines as l', 'l.journal_entry_id', '=', 'journal_entries.id')
            ->join('chart_of_accounts as c', 'c.id', '=', 'l.account_id')
            ->join('journals as j', 'j.id', '=', 'journal_entries.journal_id')
            ->orderBy('journal_entries.date')
            ->orderBy('journal_entries.piece_no')
            ->orderBy('l.sequence')
            ->get([
                'journal_entries.date',
                'journal_entries.value_date',
                'journal_entries.piece_no',
                'journal_entries.label as entry_label',
                'j.code as journal_code',
                'c.code as account_code',
                'c.name as account_name',
                'l.label as line_label',
                'l.partner_type',
                'l.partner_id',
                'l.debit',
                'l.credit',
            ]);

        return $rows->map(static fn (object $r): array => [
            'date' => (string) $r->date,
            'value_date' => (string) $r->value_date,
            'piece_no' => (string) $r->piece_no,
            'journal_code' => (string) $r->journal_code,
            'account_code' => (string) $r->account_code,
            'account_name' => (string) $r->account_name,
            'label' => (string) ($r->line_label ?: $r->entry_label),
            'partner' => $r->partner_type === null ? '' : $r->partner_type.'#'.$r->partner_id,
            'debit' => (int) $r->debit,
            'credit' => (int) $r->credit,
        ])->all();
    }
}
```

- [ ] **Step 4: Add the shared fixture helper at the top of the test file**

```php
/**
 * A fiscal year holding one posted entry, one reversed entry and one draft.
 * Returns [FiscalYear, posted, reversed, draft].
 *
 * @return array{0: object, 1: object, 2: object, 3: object}
 */
function bookFixture(): array
{
    $seeder = new \Database\Seeders\RolePermissionSeeder();
    $seeder->run();

    $user = \App\Modules\Identity\Models\User::factory()->create();
    $user->assignRole(\App\Modules\Identity\Domain\Role::SuperAdmin->value);
    \Illuminate\Support\Facades\Auth::setUser($user);

    $fiscalYear = \App\Modules\Accounting\Models\FiscalYear::factory()->create([
        'starts_on' => '2026-01-01',
        'ends_on' => '2026-12-31',
    ]);

    $posted = \App\Modules\Accounting\Models\JournalEntry::factory()->create([
        'fiscal_year_id' => $fiscalYear->id,
        'date' => '2026-03-01',
        'piece_no' => 'OD/2026/000001',
        'status' => \App\Modules\Accounting\Models\JournalEntry::STATUS_POSTED,
    ]);

    $reversed = \App\Modules\Accounting\Models\JournalEntry::factory()->create([
        'fiscal_year_id' => $fiscalYear->id,
        'date' => '2026-04-01',
        'piece_no' => 'OD/2026/000002',
        'status' => \App\Modules\Accounting\Models\JournalEntry::STATUS_REVERSED,
    ]);

    $draft = \App\Modules\Accounting\Models\JournalEntry::factory()->create([
        'fiscal_year_id' => $fiscalYear->id,
        'date' => '2026-05-01',
        'piece_no' => 'OD/2026/000003',
        'status' => \App\Modules\Accounting\Models\JournalEntry::STATUS_DRAFT,
    ]);

    foreach ([$posted, $reversed, $draft] as $entry) {
        \App\Modules\Accounting\Models\JournalEntryLine::factory()->create([
            'journal_entry_id' => $entry->id,
            'sequence' => 1,
            'debit' => 100000,
            'credit' => 0,
        ]);
        \App\Modules\Accounting\Models\JournalEntryLine::factory()->create([
            'journal_entry_id' => $entry->id,
            'sequence' => 2,
            'debit' => 0,
            'credit' => 100000,
        ]);
    }

    return [$fiscalYear, $posted, $reversed, $draft];
}
```

**If a factory named above does not exist, create it first** — check with:
```bash
ls database/factories/ | grep -iE "journalentry|fiscalyear"
```
If missing, build the fixture with `DB::table(...)->insertGetId([...])` instead, supplying every NOT NULL column the migration defines.

- [ ] **Step 5: Run the test and confirm it passes**

Run:
```bash
cd /c/laragon/www/opeschool-cloud && DB_DATABASE=opeschool_test /c/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe artisan test --filter=StatutoryBookTest
```
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Modules/Accounting/Actions/Books/BuildLivreJournal.php tests/Feature/Accounting/StatutoryBookTest.php
git commit -m "feat(accounting): livre-journal builder, posted+reversed per L13"
```

---

### Task 3: Balance générale builder

**Files:**
- Create: `app/Modules/Accounting/Actions/Books/BuildBalanceGenerale.php`
- Modify: `tests/Feature/Accounting/StatutoryBookTest.php`

- [ ] **Step 1: Write the failing test**

```php
it('balances the balance generale: total debit equals total credit', function (): void {
    [$fiscalYear] = bookFixture();

    $result = app(\App\Modules\Accounting\Actions\Books\BuildBalanceGenerale::class)
        ->handle((int) $fiscalYear->id, '2026-01-01', '2026-12-31');

    expect($result['totals']['closing_debit'])->toBe($result['totals']['closing_credit'])
        ->and($result['totals']['movement_debit'])->toBe($result['totals']['movement_credit']);
});
```

- [ ] **Step 2: Run it and confirm it fails**

Run:
```bash
cd /c/laragon/www/opeschool-cloud && DB_DATABASE=opeschool_test /c/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe artisan test --filter=StatutoryBookTest
```
Expected: FAIL — class not found

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions\Books;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Identity\Domain\Permission;
use Illuminate\Support\Facades\Gate;

/**
 * §14.2 - the balance generale: per account, opening debit/credit, period
 * movement debit/credit, closing debit/credit, with a grand total where
 * Sigma debit = Sigma credit.
 *
 * Opening is everything in the fiscal year strictly BEFORE period_start;
 * movement is the period itself. Presented in the SYSCOHADA convention: a
 * net debit balance prints in the debit column and a net credit balance in
 * the credit column, never a signed number in one column.
 */
final class BuildBalanceGenerale
{
    /**
     * @return array{rows: list<array<string, mixed>>, totals: array<string, int>}
     */
    public function handle(int $fiscalYearId, string $periodStart, string $periodEnd): array
    {
        Gate::authorize(Permission::LedgerView->value);

        $opening = $this->aggregate($fiscalYearId, null, $periodStart, true);
        $movement = $this->aggregate($fiscalYearId, $periodStart, $periodEnd, false);

        $codes = array_unique(array_merge(array_keys($opening), array_keys($movement)));
        sort($codes);

        $rows = [];
        $totals = [
            'opening_debit' => 0, 'opening_credit' => 0,
            'movement_debit' => 0, 'movement_credit' => 0,
            'closing_debit' => 0, 'closing_credit' => 0,
        ];

        foreach ($codes as $code) {
            $o = $opening[$code] ?? ['name' => '', 'debit' => 0, 'credit' => 0];
            $m = $movement[$code] ?? ['name' => '', 'debit' => 0, 'credit' => 0];

            $openingNet = $o['debit'] - $o['credit'];
            $closingNet = $openingNet + ($m['debit'] - $m['credit']);

            $row = [
                'account_code' => $code,
                'account_name' => $o['name'] !== '' ? $o['name'] : $m['name'],
                'opening_debit' => max($openingNet, 0),
                'opening_credit' => max(-$openingNet, 0),
                'movement_debit' => $m['debit'],
                'movement_credit' => $m['credit'],
                'closing_debit' => max($closingNet, 0),
                'closing_credit' => max(-$closingNet, 0),
            ];

            foreach (array_keys($totals) as $k) {
                $totals[$k] += $row[$k];
            }

            $rows[] = $row;
        }

        return ['rows' => $rows, 'totals' => $totals];
    }

    /**
     * @return array<string, array{name: string, debit: int, credit: int}>
     */
    private function aggregate(int $fiscalYearId, ?string $from, string $to, bool $exclusiveTo): array
    {
        $query = JournalEntry::query()
            ->postedLedger()
            ->where('journal_entries.fiscal_year_id', $fiscalYearId)
            ->join('journal_entry_lines as l', 'l.journal_entry_id', '=', 'journal_entries.id')
            ->join('chart_of_accounts as c', 'c.id', '=', 'l.account_id');

        if ($from !== null) {
            $query->where('journal_entries.date', '>=', $from);
        }

        $query->where('journal_entries.date', $exclusiveTo ? '<' : '<=', $to);

        $rows = $query->groupBy('c.code', 'c.name')
            ->get([
                'c.code',
                'c.name',
                \Illuminate\Support\Facades\DB::raw('SUM(l.debit) as d'),
                \Illuminate\Support\Facades\DB::raw('SUM(l.credit) as cr'),
            ]);

        $out = [];

        foreach ($rows as $r) {
            $out[(string) $r->code] = [
                'name' => (string) $r->name,
                'debit' => (int) $r->d,
                'credit' => (int) $r->cr,
            ];
        }

        return $out;
    }
}
```

- [ ] **Step 4: Run the test and confirm it passes**

Run:
```bash
cd /c/laragon/www/opeschool-cloud && DB_DATABASE=opeschool_test /c/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe artisan test --filter=StatutoryBookTest
```
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Accounting/Actions/Books/BuildBalanceGenerale.php tests/Feature/Accounting/StatutoryBookTest.php
git commit -m "feat(accounting): balance generale builder with SYSCOHADA column convention"
```

---

### Task 4: Grand livre builder

**Files:**
- Create: `app/Modules/Accounting/Actions/Books/BuildGrandLivre.php`
- Modify: `tests/Feature/Accounting/StatutoryBookTest.php`

- [ ] **Step 1: Write the failing test**

```php
it('carries a running balance per account in the grand livre', function (): void {
    [$fiscalYear] = bookFixture();

    $accounts = app(\App\Modules\Accounting\Actions\Books\BuildGrandLivre::class)
        ->handle((int) $fiscalYear->id, '2026-01-01', '2026-12-31');

    expect($accounts)->not->toBeEmpty();

    foreach ($accounts as $account) {
        $running = $account['opening_balance'];

        foreach ($account['movements'] as $movement) {
            $running += $movement['debit'] - $movement['credit'];
            expect($movement['running_balance'])->toBe($running);
        }

        expect($account['closing_balance'])->toBe($running);
    }
});
```

- [ ] **Step 2: Run it and confirm it fails**

Run:
```bash
cd /c/laragon/www/opeschool-cloud && DB_DATABASE=opeschool_test /c/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe artisan test --filter=StatutoryBookTest
```
Expected: FAIL — class not found

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions\Books;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Identity\Domain\Permission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * §14.2 - the grand livre: per account in code order, opening balance, every
 * movement, a running balance, closing balance.
 *
 * The running balance is computed in PHP rather than by a window function so
 * the arithmetic is plain integer minor units and identical on every MySQL
 * version the product supports.
 */
final class BuildGrandLivre
{
    /**
     * @return list<array<string, mixed>>
     */
    public function handle(int $fiscalYearId, string $periodStart, string $periodEnd): array
    {
        Gate::authorize(Permission::LedgerView->value);

        $openingRows = JournalEntry::query()
            ->postedLedger()
            ->where('journal_entries.fiscal_year_id', $fiscalYearId)
            ->where('journal_entries.date', '<', $periodStart)
            ->join('journal_entry_lines as l', 'l.journal_entry_id', '=', 'journal_entries.id')
            ->join('chart_of_accounts as c', 'c.id', '=', 'l.account_id')
            ->groupBy('c.code')
            ->get(['c.code', DB::raw('SUM(l.debit) - SUM(l.credit) as net')]);

        $opening = [];

        foreach ($openingRows as $r) {
            $opening[(string) $r->code] = (int) $r->net;
        }

        $movements = JournalEntry::query()
            ->postedLedger()
            ->where('journal_entries.fiscal_year_id', $fiscalYearId)
            ->whereBetween('journal_entries.date', [$periodStart, $periodEnd])
            ->join('journal_entry_lines as l', 'l.journal_entry_id', '=', 'journal_entries.id')
            ->join('chart_of_accounts as c', 'c.id', '=', 'l.account_id')
            ->orderBy('c.code')
            ->orderBy('journal_entries.date')
            ->orderBy('journal_entries.piece_no')
            ->orderBy('l.sequence')
            ->get([
                'c.code as account_code',
                'c.name as account_name',
                'journal_entries.date',
                'journal_entries.piece_no',
                'journal_entries.label as entry_label',
                'l.label as line_label',
                'l.partner_type',
                'l.partner_id',
                'l.debit',
                'l.credit',
            ]);

        $byAccount = [];

        foreach ($movements as $m) {
            $code = (string) $m->account_code;

            if (! isset($byAccount[$code])) {
                $byAccount[$code] = [
                    'account_code' => $code,
                    'account_name' => (string) $m->account_name,
                    'opening_balance' => $opening[$code] ?? 0,
                    'movements' => [],
                    'closing_balance' => $opening[$code] ?? 0,
                ];
            }

            $running = $byAccount[$code]['closing_balance'] + ((int) $m->debit - (int) $m->credit);

            $byAccount[$code]['movements'][] = [
                'date' => (string) $m->date,
                'piece_no' => (string) $m->piece_no,
                'label' => (string) ($m->line_label ?: $m->entry_label),
                'partner' => $m->partner_type === null ? '' : $m->partner_type.'#'.$m->partner_id,
                'debit' => (int) $m->debit,
                'credit' => (int) $m->credit,
                'running_balance' => $running,
            ];

            $byAccount[$code]['closing_balance'] = $running;
        }

        ksort($byAccount);

        return array_values($byAccount);
    }
}
```

- [ ] **Step 4: Run the test and confirm it passes**

Run:
```bash
cd /c/laragon/www/opeschool-cloud && DB_DATABASE=opeschool_test /c/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe artisan test --filter=StatutoryBookTest
```
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Accounting/Actions/Books/BuildGrandLivre.php tests/Feature/Accounting/StatutoryBookTest.php
git commit -m "feat(accounting): grand livre builder with running balances"
```

---

### Task 5: Generate, hash, sign and supersede

**Files:**
- Create: `app/Modules/Accounting/Actions/Books/GenerateStatutoryBook.php`
- Create: `resources/views/reports/statutory-book.blade.php`
- Modify: `tests/Feature/Accounting/StatutoryBookTest.php`

- [ ] **Step 1: Write the failing test**

```php
it('supersedes rather than replaces when a book is regenerated', function (): void {
    [$fiscalYear] = bookFixture();

    $action = app(\App\Modules\Accounting\Actions\Books\GenerateStatutoryBook::class);
    $type = \App\Modules\Accounting\Domain\StatutoryBookType::LivreJournal;

    $first = $action->handle($type, (int) $fiscalYear->id, '2026-01-01', '2026-12-31');
    $second = $action->handle($type, (int) $fiscalYear->id, '2026-01-01', '2026-12-31');

    expect($second->id)->not->toBe($first->id)
        ->and($second->supersedes_book_id)->toBe($first->id)
        ->and(\App\Modules\Accounting\Models\StatutoryBook::find($first->id))->not->toBeNull()
        ->and($first->sha256)->toHaveLength(64);
});
```

- [ ] **Step 2: Run it and confirm it fails**

Run:
```bash
cd /c/laragon/www/opeschool-cloud && DB_DATABASE=opeschool_test /c/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe artisan test --filter=StatutoryBookTest
```
Expected: FAIL — class not found

- [ ] **Step 3: Create the PDF shell**

```blade
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 90px 40px 60px 40px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #111; }
        header { position: fixed; top: -70px; left: 0; right: 0; text-align: center; }
        footer { position: fixed; bottom: -40px; left: 0; right: 0; font-size: 8px; text-align: center; }
        .pagenum:before { content: counter(page); }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #999; padding: 3px 4px; }
        th { background: #eee; text-align: left; }
        td.num { text-align: right; font-family: DejaVu Sans Mono, monospace; }
        .cover h1 { font-size: 16px; margin: 0 0 4px; }
        .cover dt { font-weight: bold; }
    </style>
</head>
<body>
<header>
    <strong>{{ $schoolName }}</strong> — {{ $bookLabel }}<br>
    {{ $periodStart }} → {{ $periodEnd }}
</header>

<footer>
    Page <span class="pagenum"></span> — generated {{ $generatedAt }} by {{ $generatedBy }}
    @if ($coteParaphe !== '') · Cote et paraphe: {{ $coteParaphe }} @endif
</footer>

<div class="cover">
    <h1>{{ $bookLabel }}</h1>
    <p>{{ $schoolName }} — exercice {{ $fiscalYearCode }}</p>
</div>

<table>
    <thead>
    <tr>@foreach ($headers as $h)<th>{{ $h }}</th>@endforeach</tr>
    </thead>
    <tbody>
    @foreach ($rows as $row)
        <tr>
            @foreach ($row as $cell)
                <td class="{{ is_int($cell) ? 'num' : '' }}">{{ is_int($cell) ? number_format($cell, 0, ',', ' ') : $cell }}</td>
            @endforeach
        </tr>
    @endforeach
    </tbody>
</table>
</body>
</html>
```

- [ ] **Step 4: Implement the Action**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions\Books;

use App\Modules\Accounting\Domain\StatutoryBookType;
use App\Modules\Accounting\Models\StatutoryBook;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use Barryvdh\DomPDF\Facade\Pdf;
use DomainException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * §14.1 - renders a statutory book to PDF, hashes it, records it, and points
 * the new row at whatever it supersedes.
 *
 * Nothing here UPDATEs or DELETEs. A regeneration after a correction produces
 * a NEW row referencing the old one, so the sequence of versions is itself
 * auditable and the earlier book remains exactly as it was signed.
 */
final class GenerateStatutoryBook
{
    public function __construct(
        private readonly BuildLivreJournal $livreJournal,
        private readonly BuildGrandLivre $grandLivre,
        private readonly BuildBalanceGenerale $balanceGenerale,
    ) {}

    public function handle(
        StatutoryBookType $type,
        int $fiscalYearId,
        string $periodStart,
        string $periodEnd,
        ?Actor $actor = null,
    ): StatutoryBook {
        Gate::authorize(Permission::LedgerView->value);

        $user = Auth::user();

        if ($user === null) {
            throw new DomainException('Generating a statutory book is an audited act; it needs a user.');
        }

        $fiscalYear = DB::table('fiscal_years')->where('id', $fiscalYearId)->first();

        if ($fiscalYear === null) {
            throw new DomainException("Fiscal year {$fiscalYearId} does not exist.");
        }

        [$headers, $rows, $stats] = $this->content($type, $fiscalYearId, $periodStart, $periodEnd);

        $generatedAt = now();

        $pdf = Pdf::loadView('reports.statutory-book', [
            'schoolName' => (string) (DB::table('school_profiles')->value('name') ?? 'School'),
            'bookLabel' => $type->label(),
            'fiscalYearCode' => (string) $fiscalYear->code,
            'periodStart' => $periodStart,
            'periodEnd' => $periodEnd,
            'generatedAt' => $generatedAt->format('Y-m-d H:i'),
            'generatedBy' => (string) $user->name,
            'coteParaphe' => (string) (DB::table('school_profiles')->value('books_cote_paraphe_reference') ?? ''),
            'headers' => $headers,
            'rows' => $rows,
        ])->setPaper('a4', 'landscape');

        $binary = $pdf->output();
        $sha256 = hash('sha256', $binary);

        $path = sprintf(
            'statutory-books/%s-%s-%s.pdf',
            $type->value,
            (string) $fiscalYear->code,
            $generatedAt->format('YmdHis'),
        );

        Storage::disk('local')->put($path, $binary);

        return DB::transaction(function () use (
            $type, $fiscalYearId, $periodStart, $periodEnd, $generatedAt,
            $user, $path, $sha256, $stats
        ): StatutoryBook {
            $previous = StatutoryBook::query()
                ->where('book_type', $type->value)
                ->where('fiscal_year_id', $fiscalYearId)
                ->where('period_start', $periodStart)
                ->where('period_end', $periodEnd)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            return StatutoryBook::query()->create([
                'book_type' => $type->value,
                'fiscal_year_id' => $fiscalYearId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'generated_at' => $generatedAt,
                'generated_by' => (int) $user->getKey(),
                'page_count' => 0,
                'first_piece_no' => $stats['first_piece_no'],
                'last_piece_no' => $stats['last_piece_no'],
                'total_debit' => $stats['total_debit'],
                'total_credit' => $stats['total_credit'],
                'entry_count' => $stats['entry_count'],
                'line_count' => $stats['line_count'],
                'file_path' => $path,
                'sha256' => $sha256,
                'signature' => null,
                'supersedes_book_id' => $previous?->getKey(),
                'is_definitive' => false,
            ]);
        });
    }

    /**
     * @return array{0: list<string>, 1: list<list<mixed>>, 2: array<string, mixed>}
     */
    private function content(StatutoryBookType $type, int $fiscalYearId, string $start, string $end): array
    {
        if ($type === StatutoryBookType::LivreJournal) {
            $data = $this->livreJournal->handle($fiscalYearId, $start, $end);

            $rows = array_map(static fn (array $r): array => [
                $r['date'], $r['piece_no'], $r['journal_code'], $r['account_code'],
                $r['label'], $r['partner'], $r['debit'], $r['credit'],
            ], $data);

            $pieces = array_column($data, 'piece_no');

            return [
                ['Date', 'Piece', 'Jnl', 'Compte', 'Libelle', 'Tiers', 'Debit', 'Credit'],
                $rows,
                [
                    'first_piece_no' => $pieces === [] ? null : (string) min($pieces),
                    'last_piece_no' => $pieces === [] ? null : (string) max($pieces),
                    'total_debit' => array_sum(array_column($data, 'debit')),
                    'total_credit' => array_sum(array_column($data, 'credit')),
                    'entry_count' => count(array_unique($pieces)),
                    'line_count' => count($data),
                ],
            ];
        }

        if ($type === StatutoryBookType::BalanceGenerale) {
            $result = $this->balanceGenerale->handle($fiscalYearId, $start, $end);

            $rows = array_map(static fn (array $r): array => [
                $r['account_code'], $r['account_name'],
                $r['opening_debit'], $r['opening_credit'],
                $r['movement_debit'], $r['movement_credit'],
                $r['closing_debit'], $r['closing_credit'],
            ], $result['rows']);

            return [
                ['Compte', 'Intitule', 'AN Debit', 'AN Credit', 'Mvt Debit', 'Mvt Credit', 'Solde Debit', 'Solde Credit'],
                $rows,
                [
                    'first_piece_no' => null,
                    'last_piece_no' => null,
                    'total_debit' => $result['totals']['closing_debit'],
                    'total_credit' => $result['totals']['closing_credit'],
                    'entry_count' => 0,
                    'line_count' => count($result['rows']),
                ],
            ];
        }

        if ($type === StatutoryBookType::GrandLivre) {
            $accounts = $this->grandLivre->handle($fiscalYearId, $start, $end);

            $rows = [];
            $lineCount = 0;
            $totalDebit = 0;
            $totalCredit = 0;

            foreach ($accounts as $account) {
                $rows[] = [
                    $account['account_code'],
                    $account['account_name'],
                    'Solde d\'ouverture', '', '', $account['opening_balance'],
                ];

                foreach ($account['movements'] as $m) {
                    $rows[] = [
                        '', $m['date'], $m['piece_no'].' '.$m['label'],
                        $m['debit'], $m['credit'], $m['running_balance'],
                    ];
                    $lineCount++;
                    $totalDebit += $m['debit'];
                    $totalCredit += $m['credit'];
                }
            }

            return [
                ['Compte', 'Date', 'Libelle', 'Debit', 'Credit', 'Solde'],
                $rows,
                [
                    'first_piece_no' => null,
                    'last_piece_no' => null,
                    'total_debit' => $totalDebit,
                    'total_credit' => $totalCredit,
                    'entry_count' => count($accounts),
                    'line_count' => $lineCount,
                ],
            ];
        }

        throw new DomainException(
            "The livre d'inventaire is generated at year-end close by its own Action (§14.2); "
            .'it transcribes the Bilan, Compte de resultat, Tableau des flux and the physical '
            .'inventory, none of which this generic renderer produces.'
        );
    }
}
```

- [ ] **Step 5: Run the test and confirm it passes**

Run:
```bash
cd /c/laragon/www/opeschool-cloud && DB_DATABASE=opeschool_test /c/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe artisan test --filter=StatutoryBookTest
```
Expected: PASS — 4 tests

- [ ] **Step 6: Commit**

```bash
git add app/Modules/Accounting/Actions/Books/GenerateStatutoryBook.php resources/views/reports/statutory-book.blade.php tests/Feature/Accounting/StatutoryBookTest.php
git commit -m "feat(accounting): generate, hash and supersede statutory books"
```

---

### Task 6: Wire the screen — route, alias, nav, both locales, icon

**Files:**
- Create: `app/Modules/Accounting/Livewire/Books/Index.php`
- Create: `resources/views/livewire/accounting/books/index.blade.php`
- Modify: `routes/web.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `app/Modules/Identity/Support/Navigation.php`
- Modify: `lang/en/opes.php`, `lang/fr/opes.php`
- Modify: `resources/views/components/opes-nav-icon.blade.php`

- [ ] **Step 1: Create the component**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Livewire\Books;

use App\Modules\Accounting\Actions\Books\GenerateStatutoryBook;
use App\Modules\Accounting\Domain\StatutoryBookType;
use App\Modules\Accounting\Models\StatutoryBook;
use App\Support\Audit\Actor;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Component;

final class Index extends Component
{
    public string $fiscalYearId = '';

    public string $bookType = 'livre_journal';

    public string $message = '';

    public string $error = '';

    public function mount(): void
    {
        if ($this->fiscalYearId === '') {
            $id = DB::table('fiscal_years')->orderByDesc('id')->value('id');
            $this->fiscalYearId = $id === null ? '' : (string) $id;
        }
    }

    public function generate(): void
    {
        $this->message = '';
        $this->error = '';

        try {
            $year = DB::table('fiscal_years')->where('id', (int) $this->fiscalYearId)->first();

            if ($year === null) {
                $this->error = 'Select a fiscal year first.';

                return;
            }

            $user = Auth::user();

            $book = app(GenerateStatutoryBook::class)->handle(
                StatutoryBookType::from($this->bookType),
                (int) $this->fiscalYearId,
                (string) $year->starts_on,
                (string) $year->ends_on,
                $user === null ? null : new Actor((int) $user->getKey(), (string) $user->name),
            );

            $this->message = sprintf('Generated %s (sha256 %s).', $book->book_type->label(), substr($book->sha256, 0, 12));
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function render(): View
    {
        return view('livewire.accounting.books.index', [
            'fiscalYears' => DB::table('fiscal_years')->orderByDesc('id')->get(),
            'bookTypes' => StatutoryBookType::cases(),
            'books' => StatutoryBook::query()
                ->when($this->fiscalYearId !== '', fn ($q) => $q->where('fiscal_year_id', (int) $this->fiscalYearId))
                ->orderByDesc('id')
                ->limit(50)
                ->get(),
        ]);
    }
}
```

- [ ] **Step 2: Create the view (single root element)**

```blade
<div class="space-y-6">
    <header>
        <h1 class="text-xl font-semibold text-charcoal">{{ __('opes.books_screen.title') }}</h1>
        <p class="text-sm text-slate-600">{{ __('opes.books_screen.intro') }}</p>
    </header>

    @if ($message !== '')
        <p class="rounded border border-primary/40 bg-primary/10 p-3 text-sm text-primary" role="status">{{ $message }}</p>
    @endif

    @if ($error !== '')
        <p class="rounded border border-heritage-red/40 bg-heritage-red/10 p-3 text-sm text-heritage-red" role="alert">{{ $error }}</p>
    @endif

    <section class="rounded-lg border border-sand bg-white p-4 shadow-sm">
        <div class="flex flex-wrap items-end gap-3">
            <label class="text-sm">
                <span class="block text-slate-600">{{ __('opes.books_screen.fiscal_year') }}</span>
                <select wire:model="fiscalYearId" class="mt-1 rounded border border-sand p-2">
                    @foreach ($fiscalYears as $year)
                        <option value="{{ $year->id }}">{{ $year->code }}</option>
                    @endforeach
                </select>
            </label>

            <label class="text-sm">
                <span class="block text-slate-600">{{ __('opes.books_screen.book_type') }}</span>
                <select wire:model="bookType" class="mt-1 rounded border border-sand p-2">
                    @foreach ($bookTypes as $type)
                        <option value="{{ $type->value }}">{{ $type->label() }}</option>
                    @endforeach
                </select>
            </label>

            <button type="button" wire:click="generate"
                    class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white">
                {{ __('opes.books_screen.generate') }}
            </button>
        </div>
    </section>

    <section class="overflow-x-auto rounded-lg border border-sand bg-white shadow-sm">
        <table class="min-w-full text-sm">
            <thead class="bg-sand/40">
            <tr>
                <th class="p-2 text-left">{{ __('opes.books_screen.book') }}</th>
                <th class="p-2 text-left">{{ __('opes.books_screen.period') }}</th>
                <th class="p-2 text-left">{{ __('opes.books_screen.generated') }}</th>
                <th class="p-2 text-right">{{ __('opes.books_screen.lines') }}</th>
                <th class="p-2 text-left">{{ __('opes.books_screen.hash') }}</th>
                <th class="p-2 text-left">{{ __('opes.books_screen.supersedes') }}</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($books as $book)
                <tr class="border-t border-sand">
                    <td class="p-2">{{ $book->book_type->label() }}</td>
                    <td class="p-2">{{ $book->period_start?->format('Y-m-d') }} → {{ $book->period_end?->format('Y-m-d') }}</td>
                    <td class="p-2">{{ $book->generated_at?->format('Y-m-d H:i') }}</td>
                    <td class="p-2 text-right font-mono">{{ number_format($book->line_count) }}</td>
                    <td class="p-2 font-mono text-xs">{{ substr($book->sha256, 0, 16) }}…</td>
                    <td class="p-2">{{ $book->supersedes_book_id ? '#'.$book->supersedes_book_id : '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="p-4 text-center text-slate-500">{{ __('opes.books_screen.empty') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </section>
</div>
```

- [ ] **Step 3: Add the route**

In `routes/web.php`, beside the other accounting routes:

```php
    /*
     * The four AUDCIF Art. 19 books (02-accounting §14). Legal registers, not
     * reports: generated, hashed and superseded, never regenerated in place.
     */
    Route::get('/accounting/books', \App\Modules\Accounting\Livewire\Books\Index::class)
        ->middleware('can:ledger.view')->name('accounting.books');
```

- [ ] **Step 4: Register the Livewire alias**

In `app/Providers/AppServiceProvider.php`, add the import beside the other accounting imports:

```php
use App\Modules\Accounting\Livewire\Books\Index as AccountingBooksIndex;
```

and the registration beside the other accounting components:

```php
        Livewire::component('accounting.books.index', AccountingBooksIndex::class);
```

- [ ] **Step 5: Add the nav entry**

In `app/Modules/Identity/Support/Navigation.php`, after the `reconciliation` entry:

```php
            // 02-accounting §14: the four AUDCIF Art. 19 statutory books.
            ['key' => 'books', 'route' => '/accounting/books', 'permission' => Permission::LedgerView, 'enabled' => true, 'built' => true],
```

- [ ] **Step 6: Add lang keys to BOTH locales**

`lang/en/opes.php` — in the `nav` group beside `reconciliation`:

```php
        'books' => 'Statutory books',
```

and a new group beside `budgets_screen`:

```php
    'books_screen' => [
        'title' => 'Statutory books',
        'intro' => 'The four books required by AUDCIF Art. 19. Each generation is hashed and immutable; regenerating supersedes rather than replaces.',
        'fiscal_year' => 'Fiscal year',
        'book_type' => 'Book',
        'generate' => 'Generate',
        'book' => 'Book',
        'period' => 'Period',
        'generated' => 'Generated',
        'lines' => 'Lines',
        'hash' => 'SHA-256',
        'supersedes' => 'Supersedes',
        'empty' => 'No book has been generated for this fiscal year yet.',
    ],
```

`lang/fr/opes.php` — in the `nav` group:

```php
        'books' => 'Livres obligatoires',
```

and:

```php
    'books_screen' => [
        'title' => 'Livres obligatoires',
        'intro' => "Les quatre livres exiges par l'article 19 de l'AUDCIF. Chaque generation est hachee et immuable ; une regeneration remplace par substitution, jamais sur place.",
        'fiscal_year' => 'Exercice',
        'book_type' => 'Livre',
        'generate' => 'Generer',
        'book' => 'Livre',
        'period' => 'Periode',
        'generated' => 'Genere le',
        'lines' => 'Lignes',
        'hash' => 'SHA-256',
        'supersedes' => 'Remplace',
        'empty' => "Aucun livre n'a encore ete genere pour cet exercice.",
    ],
```

- [ ] **Step 7: Add the nav icon**

In `resources/views/components/opes-nav-icon.blade.php`, beside the other accounting icons:

```php
        // Statutory books: a bound ledger.
        'books' => '<path d="M4 5a2 2 0 012-2h13v18H6a2 2 0 01-2-2z"/><path d="M9 3v18"/>',
```

- [ ] **Step 8: Verify locale parity**

Run:
```bash
cd /c/laragon/www/opeschool-cloud && /c/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe -r '$en=require "lang/en/opes.php"; $fr=require "lang/fr/opes.php"; function flat($a,$p=""){ $o=[]; foreach($a as $k=>$v){ $key=$p===""?$k:"$p.$k"; if(is_array($v)) $o=array_merge($o,flat($v,$key)); else $o[]=$key; } return $o; } $e=flat($en);$f=flat($fr); printf("en=%d fr=%d diff=%d\n",count($e),count($f),count(array_diff($e,$f))+count(array_diff($f,$e)));'
```
Expected: `diff=0`

- [ ] **Step 9: Prove the route boots over real HTTP**

Run:
```bash
cd /c/laragon/www/opeschool-cloud && DB_DATABASE=opeschool_test /c/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe artisan test --filter="RouteSmokeTest|LocalisationTest"
```
Expected: PASS. If `accounting/books -> 500` appears, the Livewire alias in Step 4 is missing or misspelled.

- [ ] **Step 10: Commit**

```bash
git add app/Modules/Accounting/Livewire/Books resources/views/livewire/accounting/books routes/web.php app/Providers/AppServiceProvider.php app/Modules/Identity/Support/Navigation.php lang resources/views/components/opes-nav-icon.blade.php
git commit -m "feat(accounting): wire the statutory books screen with en/fr parity"
```

---

### Task 7: Generate the real books against demo data

**Files:**
- Create: `database/seeders/StatutoryBookDemoSeeder.php`

- [ ] **Step 1: Write the seeder**

```php
<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Accounting\Actions\Books\GenerateStatutoryBook;
use App\Modules\Accounting\Domain\StatutoryBookType;
use App\Modules\Accounting\Models\StatutoryBook;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Generates the three period books for every fiscal year that has none.
 * Idempotent and additive: a year already carrying a book is skipped, so a
 * re-run never creates a pointless supersession chain.
 */
final class StatutoryBookDemoSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::query()->where('email', 'demo.admin@opeschool.test')->first();

        if ($admin === null) {
            $this->command?->warn('StatutoryBookDemoSeeder: no demo admin; skipping.');

            return;
        }

        Auth::setUser($admin);

        $types = [
            StatutoryBookType::LivreJournal,
            StatutoryBookType::GrandLivre,
            StatutoryBookType::BalanceGenerale,
        ];

        foreach (DB::table('fiscal_years')->orderBy('id')->get() as $year) {
            foreach ($types as $type) {
                $exists = StatutoryBook::query()
                    ->where('fiscal_year_id', (int) $year->id)
                    ->where('book_type', $type->value)
                    ->exists();

                if ($exists) {
                    $this->command?->info("{$type->label()} for {$year->code} already generated; skipping.");

                    continue;
                }

                $book = app(GenerateStatutoryBook::class)->handle(
                    $type,
                    (int) $year->id,
                    (string) $year->starts_on,
                    (string) $year->ends_on,
                );

                $this->command?->info(sprintf(
                    '%s for %s: %d lines, sha256 %s',
                    $type->label(),
                    (string) $year->code,
                    $book->line_count,
                    substr($book->sha256, 0, 12),
                ));
            }
        }
    }
}
```

- [ ] **Step 2: Run it against the demo database**

Run:
```bash
cd /c/laragon/www/opeschool-cloud && /c/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe artisan db:seed --class=StatutoryBookDemoSeeder --force
```
Expected: three lines per fiscal year reporting line counts and hashes.

- [ ] **Step 3: Confirm the balance générale actually balances on real data**

Run:
```bash
cd /c/laragon/www/opeschool-cloud && /c/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe -r 'require "vendor/autoload.php";$a=require "bootstrap/app.php";$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); foreach(App\Modules\Accounting\Models\StatutoryBook::all() as $b){ printf("%-18s %-8s lines=%-5d D=%-12s C=%-12s balanced=%s\n", $b->book_type->value, $b->fiscal_year_id, $b->line_count, number_format($b->total_debit), number_format($b->total_credit), $b->total_debit===$b->total_credit?"YES":"no"); }'
```
Expected: the `balance_generale` rows show `balanced=YES`.

- [ ] **Step 4: Confirm re-running supersedes rather than duplicating**

Run the seeder a second time. Expected: every line reports "already generated; skipping."

- [ ] **Step 5: Commit**

```bash
git add database/seeders/StatutoryBookDemoSeeder.php
git commit -m "feat(accounting): seed statutory books for the demo fiscal years"
```

---

## Deferred to a later plan, deliberately

- **Livre d'inventaire** (§14.2). It transcribes the Bilan, Compte de résultat, Tableau des flux and the physical inventory summary, and is generated once per year *after* the §17 year-end sequence. `GenerateStatutoryBook` throws a clear message for it rather than producing a half-book.
- **Detached signature** (`signature` column). The mechanism is `00-core` §13.5 and it needs the instance signing key, which cannot create an EC key on this machine until `OPENSSL_CONF` is set. The column ships nullable and the hash is real.
- **`page_count`**. dompdf knows the count only after rendering; wiring it needs a second pass over the generated PDF.
- **Cote et paraphe fields** on `SchoolProfile` (§14.3). The renderer reads `books_cote_paraphe_reference` and prints nothing when absent; adding the four columns is a separate migration.
- **Documentation du système comptable** (§14.4, `GenerateAccountingSystemDocumentation`). AUDCIF requires a computerised system to hold a description of itself, generated from live configuration so it cannot drift. It is its own deliverable and gets its own plan.
