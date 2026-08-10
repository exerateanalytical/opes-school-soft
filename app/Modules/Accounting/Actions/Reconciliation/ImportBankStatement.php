<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions\Reconciliation;

use App\Modules\Accounting\Domain\StatementLineStatus;
use App\Modules\Accounting\Domain\StatementSource;
use App\Modules\Accounting\Models\BankStatement;
use App\Modules\Accounting\Models\BankStatementLine;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use App\Support\Money\Money;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/02-accounting.md §13.1 - get the relevé into the system.
 *
 * Deliberately NOT a bank integration: a school in Douala receives a PDF or
 * a CSV export, and an MTN float is reconciled from the operator's own
 * export. `manual` and `csv` are the two paths, and `linesFromCsv()` below
 * is the whole of the parser.
 *
 * Two guards do the real work:
 *
 *  - the target must be a POSTABLE class-5 account with `is_reconcilable`.
 *    That flag is §13's "has an external statement to reconcile against",
 *    and it is why 571 Main Cash Box is refused here: a till is COUNTED
 *    (that is `cash_desk_sessions`), never reconciled;
 *  - `Σ(credit − debit)` over the lines must equal `closing − opening`. A
 *    statement whose own lines do not explain its own movement is a
 *    mis-keyed or truncated import, and importing it would put the blame on
 *    the books for a difference the document created. Proved with Money,
 *    never SQL arithmetic.
 */
final class ImportBankStatement
{
    public function __construct(
        private readonly WriteAuditEntry $audit,
    ) {}

    /**
     * @param  list<array{operation_date: string, value_date?: string|null, label: string, reference?: string|null, debit?: int, credit?: int}>  $lines
     */
    public function handle(
        int $treasuryAccountId,
        string $statementReference,
        string $periodStart,
        string $periodEnd,
        int $openingBalance,
        int $closingBalance,
        array $lines,
        Actor $actor,
        StatementSource $source = StatementSource::Manual,
        ?string $fileSha256 = null,
        ?string $notes = null,
    ): BankStatement {
        Gate::authorize(Permission::LedgerPost->value);

        $account = ChartOfAccount::query()->findOrFail($treasuryAccountId);

        if (! $account->is_postable || $account->account_class !== 5) {
            throw new DomainException(
                sprintf('Account %s is not a postable treasury (class 5) account.', $account->code)
            );
        }

        if (! $account->is_reconcilable) {
            throw new DomainException(sprintf(
                'Account %s is not marked reconcilable; there is no external statement for it. '
                .'A cash box is counted at the desk, not reconciled.',
                $account->code,
            ));
        }

        $statementReference = trim($statementReference);

        if ($statementReference === '') {
            throw new DomainException('A statement needs the reference the bank or operator printed on it.');
        }

        if ($lines === []) {
            throw new DomainException('A statement with no lines explains nothing; import is refused.');
        }

        $start = Carbon::parse($periodStart)->startOfDay();
        $end = Carbon::parse($periodEnd)->startOfDay();

        if ($end->lessThan($start)) {
            throw new DomainException('The statement period ends before it starts.');
        }

        $movement = Money::zero();
        $normalised = [];
        $lineNo = 0;

        foreach ($lines as $line) {
            $lineNo++;

            $debit = (int) ($line['debit'] ?? 0);
            $credit = (int) ($line['credit'] ?? 0);

            if ($debit < 0 || $credit < 0) {
                throw new DomainException("Statement line {$lineNo} carries a negative amount; use the other column.");
            }

            if (($debit === 0) === ($credit === 0)) {
                throw new DomainException(
                    "Statement line {$lineNo} must be one-sided and non-zero - a relevé line is money in or money out."
                );
            }

            $operationDate = Carbon::parse($line['operation_date'])->startOfDay();

            if ($operationDate->lessThan($start) || $operationDate->greaterThan($end)) {
                throw new DomainException(sprintf(
                    'Statement line %d is dated %s, outside the statement period %s..%s.',
                    $lineNo,
                    $operationDate->toDateString(),
                    $start->toDateString(),
                    $end->toDateString(),
                ));
            }

            $movement = $movement->plus(Money::of($credit - $debit));

            $label = trim($line['label']);

            $normalised[] = [
                'line_no' => $lineNo,
                'operation_date' => $operationDate->toDateString(),
                'value_date' => ($line['value_date'] ?? '') !== ''
                    ? Carbon::parse($line['value_date'])->toDateString()
                    : null,
                'label' => $label === '' ? 'Operation '.$lineNo : $label,
                'reference' => ($line['reference'] ?? '') !== '' ? $line['reference'] : null,
                'debit' => $debit,
                'credit' => $credit,
                'status' => StatementLineStatus::Unmatched->value,
            ];
        }

        $declared = Money::of($closingBalance)->minus(Money::of($openingBalance));

        if (! $movement->equals($declared)) {
            throw new DomainException(sprintf(
                'The statement does not explain itself: its lines move %d but its balances move %d '
                .'(%d → %d). Import refused rather than blaming the books for the gap.',
                $movement->amount(),
                $declared->amount(),
                $openingBalance,
                $closingBalance,
            ));
        }

        return DB::transaction(function () use (
            $treasuryAccountId, $statementReference, $start, $end, $openingBalance,
            $closingBalance, $normalised, $actor, $source, $fileSha256, $notes,
        ): BankStatement {
            $statement = BankStatement::query()->create([
                'treasury_account_id' => $treasuryAccountId,
                'statement_reference' => $statementReference,
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'opening_balance' => $openingBalance,
                'closing_balance' => $closingBalance,
                'source' => $source->value,
                'file_sha256' => $fileSha256,
                'imported_by' => $actor->id,
                'imported_at' => now(),
                'notes' => $notes,
            ]);

            $statementId = (int) $statement->getKey();
            $now = now();

            $rows = array_map(
                static fn (array $row): array => $row + [
                    'bank_statement_id' => $statementId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                $normalised,
            );

            BankStatementLine::query()->insert($rows);

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Accounting',
                auditableType: BankStatement::class,
                auditableId: $statementId,
                after: [
                    'treasury_account_id' => $treasuryAccountId,
                    'statement_reference' => $statementReference,
                    'period' => $start->toDateString().'..'.$end->toDateString(),
                    'opening_balance' => $openingBalance,
                    'closing_balance' => $closingBalance,
                    'line_count' => count($rows),
                    'source' => $source->value,
                ],
                actor: $actor,
            );

            return $statement->refresh();
        });
    }

    /**
     * The whole CSV parser: `operation_date,value_date,label,reference,debit,credit`,
     * one header row, amounts as whole FCFA.
     *
     * Thin on purpose. Every bank in Cameroon exports a different shape, and
     * a clever column-sniffing importer that guesses wrong puts a debit in
     * the credit column - which reconciles to a difference of exactly twice
     * the amount and takes a bursar a morning to find. One documented shape,
     * loudly refused when it does not match.
     *
     * @return list<array{operation_date: string, value_date: string|null, label: string, reference: string|null, debit: int, credit: int}>
     */
    public static function linesFromCsv(string $csv): array
    {
        $rows = preg_split('/\r\n|\r|\n/', trim($csv)) ?: [];
        $rows = array_values(array_filter($rows, static fn (string $row): bool => trim($row) !== ''));

        if (count($rows) < 2) {
            throw new DomainException('The CSV needs a header row and at least one movement.');
        }

        $header = str_getcsv($rows[0], ',', '"', '\\');
        $header = array_map(static fn (?string $cell): string => strtolower(trim((string) $cell)), $header);

        $expected = ['operation_date', 'value_date', 'label', 'reference', 'debit', 'credit'];

        if ($header !== $expected) {
            throw new DomainException(
                'Unexpected CSV header. Expected exactly: '.implode(',', $expected)
            );
        }

        $lines = [];

        foreach (array_slice($rows, 1) as $index => $row) {
            $cells = str_getcsv($row, ',', '"', '\\');

            if (count($cells) !== 6) {
                throw new DomainException(sprintf('CSV row %d has %d columns, expected 6.', $index + 2, count($cells)));
            }

            $lines[] = [
                'operation_date' => trim((string) $cells[0]),
                'value_date' => trim((string) $cells[1]) === '' ? null : trim((string) $cells[1]),
                'label' => trim((string) $cells[2]),
                'reference' => trim((string) $cells[3]) === '' ? null : trim((string) $cells[3]),
                'debit' => (int) round((float) str_replace([' ', ','], '', (string) $cells[4])),
                'credit' => (int) round((float) str_replace([' ', ','], '', (string) $cells[5])),
            ];
        }

        return $lines;
    }
}
