<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions\YearEnd;

use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Models\ResultAppropriation;
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
 * docs/specs/02-accounting.md §18.3 - captures the appropriation DECISION,
 * as a draft. It posts nothing; `PostResultAppropriation` does that, once,
 * afterwards. The split is deliberate: the resolution is keyed from minutes
 * that arrive days after the closing entry, and a half-keyed allocation must
 * never be able to touch the ledger.
 *
 * `result_amount` is NOT keyed by the user. It is READ from compte 13 after
 * the §18.1 closing entry - the number in the ledger, not the number in
 * someone's spreadsheet - and the lines are checked against it (AP-1). A
 * loss is a negative result and is appropriated exactly the same way, to a
 * debit report à nouveau; nothing here special-cases the sign.
 *
 * The legal-reserve percentage is `NEEDS VERIFICATION` per §18.3 and is
 * therefore NOT computed here, ever. Which accounts receive what is keyed
 * from the resolution.
 */
final class RecordResultAppropriation
{
    public const PERMISSION = Permission::LedgerConfigure->value;

    public function __construct(
        private readonly YearEndBalances $balances,
        private readonly WriteAuditEntry $audit,
    ) {}

    /**
     * @param  list<array{account_id: int, amount: int, label: string}>  $lines
     */
    public function handle(
        int $fiscalYearId,
        string $decisionBody,
        string $decisionDate,
        string $resolutionReference,
        array $lines,
        Actor $actor,
        ?string $documentPath = null,
        ?string $documentSha256 = null,
    ): ResultAppropriation {
        Gate::authorize(self::PERMISSION);

        if (trim($decisionBody) === '' || trim($resolutionReference) === '') {
            throw new DomainException(
                'An appropriation records a real decision: the deciding body and the resolution reference are both required.'
            );
        }

        if ($lines === []) {
            throw new DomainException('An appropriation with no allocation lines empties nothing; add at least one line.');
        }

        return DB::transaction(function () use (
            $fiscalYearId, $decisionBody, $decisionDate, $resolutionReference,
            $lines, $actor, $documentPath, $documentSha256
        ): ResultAppropriation {
            /** @var FiscalYear $fiscalYear */
            $fiscalYear = FiscalYear::query()->whereKey($fiscalYearId)->lockForUpdate()->firstOrFail();

            if ($fiscalYear->closing_entry_id === null) {
                throw new DomainException(sprintf(
                    'Fiscal year %s has no closing entry yet. The result is only in compte 13 after §18.1 runs; appropriating before that would allocate a number that does not exist.',
                    $fiscalYear->code,
                ));
            }

            $resultAccount = ChartOfAccount::query()
                ->where('code', PostYearEndClosingEntry::RESULT_ACCOUNT_CODE)
                ->firstOrFail();

            // Compte 13 is debit-positive here; a PROFIT sits credit, so the
            // result is the negation.
            $resultAmount = -$this->balances->accountBalance($fiscalYearId, (int) $resultAccount->getKey());

            if ($resultAmount === 0) {
                throw new DomainException(
                    'Compte 13 is empty: either the closing entry has not run, or the result has already been appropriated.'
                );
            }

            /** @var ResultAppropriation|null $existing */
            $existing = ResultAppropriation::query()
                ->where('fiscal_year_id', $fiscalYearId)
                ->lockForUpdate()
                ->first();

            if ($existing !== null && $existing->status === ResultAppropriation::STATUS_APPROVED) {
                throw new DomainException(sprintf(
                    'The appropriation of %s was already approved (resolution %s) and posted; a correction is a reversal plus a fresh decision, not an edit.',
                    $fiscalYear->code,
                    $existing->resolution_reference,
                ));
            }

            $this->assertLinesValid($lines, $resultAmount, (int) $resultAccount->getKey());

            $appropriation = $existing ?? new ResultAppropriation;

            $appropriation->forceFill([
                'fiscal_year_id' => $fiscalYearId,
                'decision_body' => trim($decisionBody),
                'decision_date' => Carbon::parse($decisionDate)->toDateString(),
                'resolution_reference' => trim($resolutionReference),
                'result_amount' => $resultAmount,
                'status' => ResultAppropriation::STATUS_DRAFT,
                'document_path' => $documentPath,
                'document_sha256' => $documentSha256,
            ])->save();

            $appropriation->lines()->delete();

            $sequence = 1;

            foreach ($lines as $line) {
                $appropriation->lines()->create([
                    'account_id' => $line['account_id'],
                    'amount' => $line['amount'],
                    'label' => $line['label'],
                    'sequence' => $sequence,
                ]);

                $sequence++;
            }

            $this->audit->handle(
                action: $existing === null ? AuditAction::Created : AuditAction::Updated,
                module: 'Accounting',
                auditableType: ResultAppropriation::class,
                auditableId: (int) $appropriation->getKey(),
                after: [
                    'fiscal_year_id' => $fiscalYearId,
                    'result_amount' => $resultAmount,
                    'line_count' => count($lines),
                    'resolution_reference' => trim($resolutionReference),
                ],
                actor: $actor,
            );

            return $appropriation->refresh();
        });
    }

    /**
     * AP-1 plus the account discipline the posting would otherwise discover
     * at the trigger.
     *
     * @param  list<array{account_id: int, amount: int, label: string}>  $lines
     */
    private function assertLinesValid(array $lines, int $resultAmount, int $resultAccountId): void
    {
        $total = Money::zero();

        foreach ($lines as $index => $line) {
            if ($line['amount'] === 0) {
                throw new DomainException(sprintf('Allocation line #%d is zero; remove it.', $index + 1));
            }

            if ($line['account_id'] === $resultAccountId) {
                throw new DomainException(
                    'An allocation line may not target compte 13 itself - 13 is the account being EMPTIED, and the posting adds its side automatically.'
                );
            }

            /** @var ChartOfAccount|null $account */
            $account = ChartOfAccount::query()->find($line['account_id']);

            if ($account === null) {
                throw new DomainException(sprintf('Allocation line #%d names an account that does not exist.', $index + 1));
            }

            if (! $account->is_postable || $account->is_archived) {
                throw new DomainException(sprintf(
                    'Allocation line #%d targets %s, which is %s.',
                    $index + 1,
                    $account->code,
                    $account->is_archived ? 'archived' : 'not postable',
                ));
            }

            if ($account->is_collective || $account->requires_partner) {
                throw new DomainException(sprintf(
                    'Allocation line #%d targets the collective account %s. An appropriation to reserves or report à nouveau has no third party; a distribution to an identified partner belongs on its own liability account.',
                    $index + 1,
                    $account->code,
                ));
            }

            $total = $total->plus(Money::of($line['amount']));
        }

        if ($total->amount() !== $resultAmount) {
            throw new DomainException(sprintf(
                'AP-1 violated: the allocation lines total %s but compte 13 holds %s. The appropriation must empty 13 exactly - a residual is not permitted.',
                Money::of($total->amount())->format(),
                Money::of($resultAmount)->format(),
            ));
        }
    }
}
