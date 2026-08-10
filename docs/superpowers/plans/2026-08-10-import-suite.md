# Data Import Suite Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a school onboard its students, guardians and staff from a CSV file instead of typing them one at a time — the single biggest obstacle to selling this product.

**Architecture:** Three phases per file, never one. **Stage** parses the CSV into `import_rows` and writes nothing to the domain. **Validate** checks every row and marks it valid or invalid with per-field errors, still writing nothing. **Commit** calls the real domain Action for each valid row. A row already imported is skipped on re-run, so a commit interrupted halfway is resumed rather than duplicated. Invalid rows never block valid ones — the operator fixes and re-uploads only the rejects.

**Why not write rows directly:** `CreateStudent` allocates the matricule, derives status and writes the audit entry. An importer that INSERTs into `students` would bypass all three and produce records the rest of the product does not believe in.

**Tech Stack:** Laravel 13, Livewire 4, MySQL 8.4, Pest.

**Spec:** `docs/specs/00-core.md` §15 Phase 2 — "data import suite (students, staff, guardians, opening balances, opening trial balance)". `ImportOpeningTrialBalance` already exists; this plan covers the three people imports.

---

## Critical context

1. **Never `migrate:fresh` against `opeschool`** — it holds live demo data. Tests use `DB_DATABASE=opeschool_test`.
2. **PHP is** `/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe`.
3. **Three-part wiring is mandatory:** route + `Livewire::component()` alias + nav entry, plus both locales and an icon. A missing alias renders fine under `Livewire::test()` and answers **500** in a browser.
4. **Blade needs a single root element.**
5. **Payloads are stored as JSON**, and MySQL JSON columns cannot carry a DEFAULT — set them explicitly on insert.
6. **A commit must be resumable.** Mark each row `imported` with its created id inside the same transaction as the domain call.

---

## File Structure

| File | Responsibility |
|---|---|
| `database/migrations/2026_08_10_420001_create_import_tables.php` | `import_batches` + `import_rows` |
| `app/Modules/Students/Domain/ImportKind.php` | students / guardians / staff |
| `app/Modules/Students/Domain/ImportBatchStatus.php` | staged / validated / committed / failed |
| `app/Modules/Students/Models/ImportBatch.php` | batch model |
| `app/Modules/Students/Models/ImportRow.php` | row model |
| `app/Modules/Students/Actions/Import/StageImportBatch.php` | CSV → rows, no domain writes |
| `app/Modules/Students/Actions/Import/ValidateImportBatch.php` | per-row validation, no domain writes |
| `app/Modules/Students/Actions/Import/CommitImportBatch.php` | calls the domain Action per valid row |
| `app/Modules/Students/Livewire/Import/Index.php` | upload → report → commit |
| `resources/views/livewire/students/import/index.blade.php` | its view |
| `tests/Feature/Students/ImportSuiteTest.php` | behaviour |

---

### Task 1: Tables, enums and models

- [ ] **Step 1: Migration** `database/migrations/2026_08_10_420001_create_import_tables.php`

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 00-core §15 Phase 2 - the data import suite.
 *
 * Two tables, because an import is three phases and not one: stage parses,
 * validate judges, commit writes. Keeping the parsed rows in `import_rows`
 * is what makes a dry run possible - an operator sees exactly which rows
 * would fail BEFORE a single student exists.
 *
 * `imported_record_id` on a row is what makes a commit resumable: a run that
 * dies halfway leaves the successful rows marked, and re-running skips them
 * rather than creating a second copy of every student.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 24);
            $table->string('original_filename', 255);
            $table->char('sha256', 64);
            $table->string('status', 16)->default('staged');
            $table->integer('row_count')->default(0);
            $table->integer('valid_count')->default(0);
            $table->integer('invalid_count')->default(0);
            $table->integer('imported_count')->default(0);
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('uploaded_at');
            $table->foreignId('committed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('committed_at')->nullable();
            $table->timestamps();

            $table->index(['kind', 'status'], 'ix_import_batches_kind_status');
        });

        DB::statement(
            'ALTER TABLE import_batches ADD CONSTRAINT ck_import_batches_kind '
            ."CHECK (kind IN ('students','guardians','staff'))"
        );

        DB::statement(
            'ALTER TABLE import_batches ADD CONSTRAINT ck_import_batches_status '
            ."CHECK (status IN ('staged','validated','committed','failed'))"
        );

        Schema::create('import_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_batch_id')->constrained('import_batches')->cascadeOnDelete();
            $table->integer('row_no');
            $table->json('payload');
            $table->string('status', 16)->default('pending');
            $table->json('errors')->nullable();
            $table->string('imported_record_type', 120)->nullable();
            $table->unsignedBigInteger('imported_record_id')->nullable();
            $table->timestamps();

            $table->unique(['import_batch_id', 'row_no'], 'uq_import_rows_batch_row');
            $table->index(['import_batch_id', 'status'], 'ix_import_rows_batch_status');
        });

        DB::statement(
            'ALTER TABLE import_rows ADD CONSTRAINT ck_import_rows_status '
            ."CHECK (status IN ('pending','valid','invalid','imported','skipped'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('import_rows');
        Schema::dropIfExists('import_batches');
    }
};
```

`import_rows` cascades on batch delete — deliberately, and it is the one cascade in this design: a staged row has no accounting or academic meaning of its own, and a discarded upload must not leave orphans. Committed rows point at real records through `imported_record_id`, which is **not** an FK precisely because it is polymorphic and because deleting a student must not silently rewrite import history.

- [ ] **Step 2: Enums**

`app/Modules/Students/Domain/ImportKind.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Students\Domain;

enum ImportKind: string
{
    case Students = 'students';
    case Guardians = 'guardians';
    case Staff = 'staff';

    /**
     * The columns a file of this kind must carry, in the order the template
     * offers them. Extra columns are ignored; missing ones are a row error.
     *
     * @return list<string>
     */
    public function requiredColumns(): array
    {
        return match ($this) {
            self::Students => ['first_name', 'last_name', 'date_of_birth', 'gender'],
            self::Guardians => ['first_name', 'last_name', 'phone'],
            self::Staff => ['first_name', 'last_name', 'hired_on'],
        };
    }

    /**
     * @return list<string>
     */
    public function optionalColumns(): array
    {
        return match ($this) {
            self::Students => ['middle_name', 'place_of_birth', 'nationality', 'religion', 'blood_group', 'phone', 'email'],
            self::Guardians => ['email', 'relationship', 'national_id_number', 'occupation', 'address'],
            self::Staff => ['middle_name', 'gender', 'phone', 'email', 'job_title'],
        };
    }
}
```

`app/Modules/Students/Domain/ImportBatchStatus.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Students\Domain;

enum ImportBatchStatus: string
{
    case Staged = 'staged';
    case Validated = 'validated';
    case Committed = 'committed';
    case Failed = 'failed';
}
```

- [ ] **Step 3: Models** — `ImportBatch` and `ImportRow`, both with `payload`/`errors` cast to `array`, `kind` and `status` cast to their enums, and `ImportBatch::rows()` a `HasMany`.

- [ ] **Step 4: Run the migration**

```bash
cd /c/laragon/www/opeschool-cloud && /c/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe artisan migrate --force
```
Expected: `2026_08_10_420001_create_import_tables .. DONE`

- [ ] **Step 5: Commit**

```bash
git add database/migrations app/Modules/Students/Domain app/Modules/Students/Models
git commit -m "feat(students): import_batches and import_rows for a three-phase import"
```

---

### Task 2: Stage — parse CSV, write nothing to the domain

- [ ] **Step 1: Write the failing test** in `tests/Feature/Students/ImportSuiteTest.php`

```php
it('stages every data row and creates no students', function (): void {
    importActor();

    $csv = "first_name,last_name,date_of_birth,gender\n"
        ."Amina,Nkemta,2011-04-02,female\n"
        ."Brice,Fotso,2010-09-15,male\n";

    $before = \App\Modules\Students\Models\Student::count();

    $batch = app(\App\Modules\Students\Actions\Import\StageImportBatch::class)
        ->handle(\App\Modules\Students\Domain\ImportKind::Students, 'students.csv', $csv);

    expect($batch->row_count)->toBe(2)
        ->and($batch->rows()->count())->toBe(2)
        ->and(\App\Modules\Students\Models\Student::count())->toBe($before);
});
```

- [ ] **Step 2: Run it, confirm it fails** (class not found)

```bash
cd /c/laragon/www/opeschool-cloud && DB_DATABASE=opeschool_test /c/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe artisan test --filter=ImportSuiteTest
```

- [ ] **Step 3: Implement `StageImportBatch`** — parse with `str_getcsv` line by line, lower-case and trim the header, map each data line to an associative payload, insert one `import_rows` row per data line with `status = pending`, and record `sha256` of the raw content on the batch. Blank lines are skipped and do not consume a row number.

- [ ] **Step 4: Run the test, confirm it passes**

- [ ] **Step 5: Commit**

---

### Task 3: Validate — judge every row, still write nothing

- [ ] **Step 1: Write the failing test**

```php
it('marks bad rows invalid with per-field errors and leaves good rows valid', function (): void {
    importActor();

    $csv = "first_name,last_name,date_of_birth,gender\n"
        ."Amina,Nkemta,2011-04-02,female\n"
        .",Fotso,not-a-date,martian\n";

    $batch = app(\App\Modules\Students\Actions\Import\StageImportBatch::class)
        ->handle(\App\Modules\Students\Domain\ImportKind::Students, 'students.csv', $csv);

    $batch = app(\App\Modules\Students\Actions\Import\ValidateImportBatch::class)->handle((int) $batch->id);

    expect($batch->valid_count)->toBe(1)
        ->and($batch->invalid_count)->toBe(1);

    $bad = $batch->rows()->where('row_no', 2)->firstOrFail();

    expect($bad->status->value)->toBe('invalid')
        ->and(array_keys($bad->errors))->toContain('first_name')
        ->and(array_keys($bad->errors))->toContain('date_of_birth')
        ->and(array_keys($bad->errors))->toContain('gender');
});
```

- [ ] **Step 2: Run it, confirm it fails**

- [ ] **Step 3: Implement `ValidateImportBatch`** — per row, run Laravel's validator against rules derived from the kind (`required` for `requiredColumns()`, `date` for date fields, `in:` for gender), store the message bag in `errors`, set `status` to `valid` or `invalid`, then update the batch counts and set its status to `validated`.

- [ ] **Step 4: Run the test, confirm it passes**

- [ ] **Step 5: Commit**

---

### Task 4: Commit — call the real domain Action, resumably

- [ ] **Step 1: Write the failing test**

```php
it('creates only the valid rows and is safe to run twice', function (): void {
    importActor();

    $csv = "first_name,last_name,date_of_birth,gender\n"
        ."Amina,Nkemta,2011-04-02,female\n"
        .",Fotso,not-a-date,martian\n";

    $stage = app(\App\Modules\Students\Actions\Import\StageImportBatch::class);
    $validate = app(\App\Modules\Students\Actions\Import\ValidateImportBatch::class);
    $commit = app(\App\Modules\Students\Actions\Import\CommitImportBatch::class);

    $batch = $stage->handle(\App\Modules\Students\Domain\ImportKind::Students, 'students.csv', $csv);
    $validate->handle((int) $batch->id);

    $before = \App\Modules\Students\Models\Student::count();
    $batch = $commit->handle((int) $batch->id);

    expect(\App\Modules\Students\Models\Student::count())->toBe($before + 1)
        ->and($batch->imported_count)->toBe(1);

    // Re-running must not create a second Amina.
    $batch = $commit->handle((int) $batch->id);

    expect(\App\Modules\Students\Models\Student::count())->toBe($before + 1)
        ->and($batch->imported_count)->toBe(1);
});
```

- [ ] **Step 2: Run it, confirm it fails**

- [ ] **Step 3: Implement `CommitImportBatch`** — iterate rows where `status = 'valid'` (never `imported`), call `CreateStudent` with the payload inside a per-row transaction, then set the row to `imported` with `imported_record_type`/`imported_record_id`. A row whose Action throws is set to `invalid` with the exception message under an `_action` key and the loop continues — one bad row must not abandon the other 1,199.

- [ ] **Step 4: Run the test, confirm it passes**

- [ ] **Step 5: Commit**

---

### Task 5: Wire the screen

- [ ] **Step 1:** `app/Modules/Students/Livewire/Import/Index.php` — a textarea or file upload for CSV, a kind selector, a **Validate** button showing the per-row report, and a **Commit** button enabled only once `valid_count > 0`. Gated on `Permission::StudentsManage`.
- [ ] **Step 2:** the Blade view, single root element, showing the invalid rows with their field errors so the operator can fix the source file.
- [ ] **Step 3:** route `/students/import` behind `can:students.manage`, named `students.import`.
- [ ] **Step 4:** `Livewire::component('students.import.index', StudentsImportIndex::class)` in `AppServiceProvider`.
- [ ] **Step 5:** nav entry under Students, `import_screen` lang group in **both** locales, and a nav icon.
- [ ] **Step 6:** verify parity is `diff=0` and run `RouteSmokeTest|LocalisationTest`.
- [ ] **Step 7:** Commit.

---

## Deferred deliberately

- **Opening balances import** — `ImportOpeningTrialBalance` and `ImportOpeningAuxiliaryBalances` already exist as Actions; giving them this same stage/validate/commit shell is a follow-up.
- **XLSX upload.** CSV first: it is what a school exports from whatever it uses today, and PhpSpreadsheet parsing is a separate concern from the three-phase pipeline.
- **Guardian↔student linking on import.** Creating guardians is in scope; attaching them to students by matricule needs a matching strategy and belongs with `MergeGuardians`.
