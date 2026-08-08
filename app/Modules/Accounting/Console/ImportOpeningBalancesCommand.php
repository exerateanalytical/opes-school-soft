<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Console;

use App\Modules\Accounting\Actions\ImportOpeningAuxiliaryBalances;
use App\Modules\Accounting\Actions\ImportOpeningTrialBalance;
use App\Modules\Identity\Models\User;
use App\Support\Money\Money;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The accountant's one-time migration tool (docs/specs/02-accounting.md
 * §18.4). Two-step by design: run without --force to see exactly what would
 * be posted and every rejected row; run again with --force to post. The
 * person driving this is a human under pressure on cut-over day - every
 * refusal names the row number and the reason.
 *
 * Trial-balance CSV columns:  account_code,debit,credit
 * Auxiliary CSV columns:      account_code,partner_type,partner_id,amount_signed,due_date
 * (a first row repeating the column names is skipped; amounts are integer
 * minor units; amount_signed is positive for debit balances, negative for
 * credit balances; due_date is optional, YYYY-MM-DD)
 *
 * §20: this command acts AS a named user (--user), never anonymously - the
 * permission gate and created_by/posted_by attribution both depend on it.
 * The Administrator counter-approval the §20 matrix asks for on the import
 * commit is deferred with the approval workflow (see the Actions).
 */
final class ImportOpeningBalancesCommand extends Command
{
    protected $signature = 'opes:ledger:import-opening
        {file : Path to the CSV file}
        {--auxiliary : The file is the per-partner breakdown of collective accounts, not the trial balance}
        {--as-of= : Cut-over date (YYYY-MM-DD), required}
        {--user= : Email of the accountant running the import, required}
        {--suspense=4712 : Code of the migration suspense account (auxiliary mode)}
        {--force : Actually post; without it the command only previews}';

    protected $description = 'One-time migration of an existing school\'s books: opening trial balance and per-partner auxiliary balances into the AN journal.';

    public function handle(ImportOpeningTrialBalance $trialBalance, ImportOpeningAuxiliaryBalances $auxiliary): int
    {
        $file = (string) $this->argument('file');
        $asOf = (string) ($this->option('as-of') ?? '');
        $email = (string) ($this->option('user') ?? '');

        if ($asOf === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf) !== 1) {
            $this->error('Give the cut-over date as --as-of=YYYY-MM-DD.');

            return self::FAILURE;
        }

        if (! is_file($file)) {
            $this->error("File not found: {$file}");

            return self::FAILURE;
        }

        if ($email === '') {
            $this->error('Give the accountant running the import as --user=email. The import is posted in their name.');

            return self::FAILURE;
        }

        /** @var User|null $user */
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("No user with email {$email}.");

            return self::FAILURE;
        }

        $fiscalYearId = DB::table('fiscal_years')
            ->where('starts_on', '<=', $asOf)
            ->where('ends_on', '>=', $asOf)
            ->value('id');

        if ($fiscalYearId === null) {
            $this->error("No fiscal year covers {$asOf}. Create the fiscal year and its periods first.");

            return self::FAILURE;
        }

        $fiscalYearId = (int) $fiscalYearId;
        $isAuxiliary = (bool) $this->option('auxiliary');

        try {
            $rows = $isAuxiliary ? $this->readAuxiliaryCsv($file) : $this->readTrialBalanceCsv($file);
        } catch (DomainException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        // Preview - always shown, so the operator confirms totals against the
        // paper trial balance before anything touches the ledger.
        if ($isAuxiliary) {
            /** @var array<int, array{account_code: string, partner_type: string, partner_id: int, amount_signed: int, due_date?: string|null}> $rows */
            $suspenseCode = (string) $this->option('suspense');
            $validation = $auxiliary->validate($rows, $suspenseCode);

            $this->info(sprintf('Auxiliary import preview: %d row(s), as of %s, fiscal year #%d.', count($rows), $asOf, $fiscalYearId));

            foreach ($validation['per_account'] as $code => $sum) {
                $this->line(sprintf('  %s: net %s', $code, Money::of($sum)->format()));
            }

            $this->line(sprintf(
                '  Net total %s vs suspense %s balance %s.',
                Money::of($validation['net_total'])->format(),
                $suspenseCode,
                Money::of($validation['suspense_balance'])->format(),
            ));
        } else {
            /** @var array<int, array{account_code: string, debit: int, credit: int}> $rows */
            $validation = $trialBalance->validate($rows);

            $this->info(sprintf('Trial-balance import preview: %d row(s), as of %s, fiscal year #%d.', count($rows), $asOf, $fiscalYearId));
            $this->line(sprintf(
                '  Total debit %s / total credit %s.',
                Money::of($validation['total_debit'])->format(),
                Money::of($validation['total_credit'])->format(),
            ));
        }

        if ($validation['errors'] !== []) {
            $this->error('The import was refused. Fix every line below and run again:');

            foreach ($validation['errors'] as $error) {
                $this->line('  - '.$error);
            }

            return self::FAILURE;
        }

        if (! (bool) $this->option('force')) {
            $this->info('Preview only - nothing was posted. Re-run with --force to post.');

            return self::SUCCESS;
        }

        // The Actions gate on ledger.post via the authenticated user; the
        // import is posted in the named accountant's name, not as "system".
        Auth::setUser($user);
        $actor = $user->toAuditActor();

        try {
            $entry = $isAuxiliary
                ? $auxiliary->handle($fiscalYearId, $rows, $asOf, $actor, (string) $this->option('suspense'))
                : $trialBalance->handle($fiscalYearId, $rows, $asOf, $actor);
        } catch (DomainException $exception) {
            $this->error('The import was refused:');

            foreach (explode("\n", $exception->getMessage()) as $line) {
                $this->line('  - '.$line);
            }

            return self::FAILURE;
        }

        $this->info(sprintf('Posted journal entry #%d, piece %s, into the AN journal.', (int) $entry->getKey(), (string) $entry->piece_no));

        return self::SUCCESS;
    }

    /**
     * @return array<int, array{account_code: string, debit: int, credit: int}>
     */
    private function readTrialBalanceCsv(string $file): array
    {
        $rows = [];

        foreach ($this->readCsv($file, 3, 'account_code,debit,credit') as $lineNo => $fields) {
            if (! is_numeric($fields[1]) || ! is_numeric($fields[2])) {
                throw new DomainException("Line {$lineNo}: debit and credit must be integer amounts in minor units (got '{$fields[1]}', '{$fields[2]}').");
            }

            $rows[] = [
                'account_code' => trim($fields[0]),
                'debit' => (int) $fields[1],
                'credit' => (int) $fields[2],
            ];
        }

        return $rows;
    }

    /**
     * @return array<int, array{account_code: string, partner_type: string, partner_id: int, amount_signed: int, due_date: string|null}>
     */
    private function readAuxiliaryCsv(string $file): array
    {
        $rows = [];

        foreach ($this->readCsv($file, 4, 'account_code,partner_type,partner_id,amount_signed[,due_date]') as $lineNo => $fields) {
            if (! is_numeric($fields[2]) || ! is_numeric($fields[3])) {
                throw new DomainException("Line {$lineNo}: partner_id and amount_signed must be integers (got '{$fields[2]}', '{$fields[3]}').");
            }

            $dueDate = trim($fields[4] ?? '');

            $rows[] = [
                'account_code' => trim($fields[0]),
                'partner_type' => trim($fields[1]),
                'partner_id' => (int) $fields[2],
                'amount_signed' => (int) $fields[3],
                'due_date' => $dueDate === '' ? null : $dueDate,
            ];
        }

        return $rows;
    }

    /**
     * @return array<int, list<string>> keyed by 1-based CSV line number
     */
    private function readCsv(string $file, int $minColumns, string $expected): array
    {
        $handle = fopen($file, 'rb');

        if ($handle === false) {
            throw new DomainException("Cannot open {$file}.");
        }

        $rows = [];
        $lineNo = 0;

        try {
            while (($fields = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                $lineNo++;

                $fields = array_map(static fn (?string $field): string => (string) $field, $fields);

                // Skip a header row and blank lines.
                if ($lineNo === 1 && strtolower(trim($fields[0])) === 'account_code') {
                    continue;
                }

                if (count($fields) === 1 && trim($fields[0]) === '') {
                    continue;
                }

                if (count($fields) < $minColumns) {
                    throw new DomainException("Line {$lineNo}: expected columns {$expected}, got ".count($fields).'.');
                }

                $rows[$lineNo] = $fields;
            }
        } finally {
            fclose($handle);
        }

        return $rows;
    }
}
