<?php

declare(strict_types=1);

/**
 * Guards for the Accounting Review layer,
 * docs/specs/2026-08-12-accounting-finance-architecture.md §9.2.
 *
 * These exist to stop a FUTURE session undoing the discipline this build
 * established. Each one encodes a mistake that was actually made and caught
 * here, so the next person does not have to catch it again.
 */

/**
 * Architecture tests do not boot the application, so there is no container
 * and no base_path(). Walk the tree with plain PHP, as ModuleBoundaryTest does.
 *
 * @return array<string, string> absolute path => contents
 */
function opesPhpFilesUnder(string ...$relativeDirs): array
{
    $root = dirname(__DIR__, 2);
    $found = [];

    foreach ($relativeDirs as $relative) {
        $full = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);

        if (! is_dir($full)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($full, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $found[$file->getPathname()] = (string) file_get_contents($file->getPathname());
            }
        }
    }

    return $found;
}

/** @return array<string, string> */
function reviewLayerFiles(): array
{
    return opesPhpFilesUnder(
        'app/Modules/Accounting/Actions/Review',
        'app/Modules/Accounting/Livewire/Review',
        'app/Support/Ledger',
    );
}

it('never invents a statutory account code, tax mapping or DSF mapping', function () {
    // §1.1, the binding rule: a wrong value that looks authoritative is worse
    // than an empty field. The review layer REPORTS on configuration; the day
    // it starts writing configuration, "Not configured" stops being trustworthy.
    foreach (reviewLayerFiles() as $path => $contents) {
        $name = basename($path);

        expect($contents)->not->toMatch('/[\'"]dsf_line_code[\'"]\s*=>/i',
            "{$name} assigns a DSF mapping. Gates are sourced, never guessed.");
        expect($contents)->not->toMatch('/ChartOfAccount::(create|firstOrCreate|updateOrCreate|forceCreate)/',
            "{$name} creates an account. The review layer is read-only.");
    }
});

it('keeps the review layer read-only', function () {
    foreach (reviewLayerFiles() as $path => $contents) {
        $name = basename($path);

        foreach (['->save()', '->delete()', '->forceDelete()', 'DB::insert(', 'DB::update(', 'DB::statement('] as $forbidden) {
            expect($contents)->not->toContain($forbidden,
                "{$name} contains [{$forbidden}]. This layer reports; it never writes.");
        }
    }
});

it('never filters the ledger on a bare posted status', function () {
    // 02-accounting §9.3: a statement includes posted AND reversed, so a
    // reversal nets its original to zero. Filtering on 'posted' alone drops
    // the original half of every reversed pair and overstates the books.
    foreach (reviewLayerFiles() as $path => $contents) {
        expect($contents)->not->toMatch("/where\(\s*['\"]status['\"]\s*,\s*['\"]posted['\"]\s*\)/",
            basename($path)." filters on 'posted' alone. Use postedLedger() or postedLedgerStatuses().");
    }
});

it('does not reintroduce the rejected Anglo-American account codes', function () {
    // §1.2. These were proposed for this SYSCOHADA ledger and rejected:
    // class 1 is capital not treasury, 40 is fournisseurs not receivables,
    // 41 is clients not revenue. Seeding them yields wrong statutory reporting.
    $rejected = ['101100', '401100', '401200', '411100'];

    foreach (opesPhpFilesUnder('app/Modules/Accounting', 'database/seeders') as $path => $contents) {
        foreach ($rejected as $code) {
            expect($contents)->not->toContain("'{$code}'",
                basename($path)." contains rejected code {$code}. See 2026-08-12-accounting-finance-architecture.md §1.2.");
        }
    }
});

it('resolves source documents through the shared kernel, not another module\'s models', function () {
    // ModuleBoundaryTest already forbids this globally, but the reverse lookup
    // is the exact place someone would be tempted to reach across - so pin it
    // where the temptation lives, with an error message that says why.
    foreach (opesPhpFilesUnder('app/Modules/Accounting') as $path => $contents) {
        foreach (['Assets\\Models', 'Procurement\\Models', 'Payroll\\Models', 'Fees\\Models'] as $foreign) {
            expect($contents)->not->toContain("use App\\Modules\\{$foreign}",
                basename($path)." imports {$foreign}. Each module resolves its OWN documents through App\\Support\\Ledger.");
        }
    }
});

it('keeps a single definition of the posted-ledger status pair', function () {
    // Two readers - the Eloquent scope and the raw-query callers - must never
    // drift. They read from one method.
    $model = (string) file_get_contents(dirname(__DIR__, 2).'/app/Modules/Accounting/Models/JournalEntry.php');

    preg_match_all('/\[self::STATUS_POSTED,\s*self::STATUS_REVERSED\]/', $model, $matches);

    expect($matches[0])->toHaveCount(1,
        'The posted/reversed pair is listed more than once in JournalEntry. Both readers must derive from postedLedgerStatuses().');
});
