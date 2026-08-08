<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions;

use App\Modules\Accounting\Models\AnalyticAxis;
use App\Modules\Accounting\Models\AnalyticValue;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Accounting\Models\JournalEntryLineAnalytic;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use App\Support\Money\Allocator;
use App\Support\Money\Money;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/02-accounting.md §12.3 - allocate one line across one axis's
 * members.
 *
 * AN-1 holds BY CONSTRUCTION: the caller supplies only proportions
 * (`share_bp`); the amounts come out of Money's largest-remainder Allocator
 * over the line's SIGNED value (debit - credit), so Σ amount = debit - credit
 * exactly - and therefore |Σ amount| equals the line's magnitude
 * (debit + credit, one side always zero) with no rounding drift, ever.
 * Hand-rolled proportional arithmetic is exactly what the Allocator exists
 * to prevent. Signed storage makes the §12.4 reconciliation
 * (Σ amount signed vs GL Σ(debit − credit)) a straight SUM.
 *
 * AN-2 is asserted before any write: Σ share_bp must be exactly 1_000_000
 * (100%, 00-core §7.2 basis points).
 *
 * Allocating to a POSTED line is legal and intended - analytics, like
 * lettering, attach after posting. L3's line-lock trigger guards the line's
 * own frozen financial columns on `journal_entry_lines`; this child table
 * is deliberately outside it. Re-allocating an axis replaces that axis's
 * rows atomically.
 */
final class AllocateLineAnalytics
{
    public const PERMISSION = Permission::LedgerPost->value;

    public const FULL_SHARE_BP = 1_000_000;

    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    /**
     * @param  list<array{valueCode: string, shareBp: int}>  $splits
     * @return list<JournalEntryLineAnalytic>
     */
    public function handle(int $lineId, string $axisCode, array $splits, Actor $actor): array
    {
        Gate::authorize(self::PERMISSION);

        if ($splits === []) {
            throw new DomainException('At least one split is required.');
        }

        // AN-2 - checked before touching the database at all.
        $totalShareBp = 0;

        foreach ($splits as $split) {
            if ($split['shareBp'] <= 0) {
                throw new DomainException('AN-2: every share must be a positive number of basis points.');
            }

            $totalShareBp += $split['shareBp'];
        }

        if ($totalShareBp !== self::FULL_SHARE_BP) {
            throw new DomainException(sprintf(
                'AN-2: shares must sum to exactly %d basis points (100%%), got %d.',
                self::FULL_SHARE_BP,
                $totalShareBp,
            ));
        }

        $valueCodes = array_column($splits, 'valueCode');

        if (count($valueCodes) !== count(array_unique($valueCodes))) {
            throw new DomainException('Each analytic value may appear at most once per allocation.');
        }

        return DB::transaction(function () use ($lineId, $axisCode, $splits, $actor): array {
            /** @var JournalEntryLine $line */
            $line = JournalEntryLine::query()->lockForUpdate()->findOrFail($lineId);

            /** @var AnalyticAxis $axis */
            $axis = AnalyticAxis::query()
                ->where('code', $axisCode)
                ->firstOrFail();

            if (! $axis->is_active || $axis->is_archived) {
                throw new DomainException(sprintf('Analytic axis %s is not active.', $axis->code));
            }

            $values = [];

            foreach ($splits as $split) {
                /** @var AnalyticValue|null $value */
                $value = AnalyticValue::query()
                    ->where('analytic_axis_id', $axis->getKey())
                    ->where('code', $split['valueCode'])
                    ->first();

                if ($value === null) {
                    throw new DomainException(sprintf(
                        'Analytic value %s does not exist on axis %s.',
                        $split['valueCode'],
                        $axis->code,
                    ));
                }

                if (! $value->is_active || $value->is_archived) {
                    throw new DomainException(sprintf('Analytic value %s is not active.', $value->code));
                }

                $values[] = $value;
            }

            // The signed line value: exactly one of debit/credit is nonzero
            // (ck_jel_one_side), so this is +magnitude on a debit line and
            // -magnitude on a credit line.
            $signed = Money::of((int) $line->debit - (int) $line->credit);

            // AN-1 by construction - never proportional arithmetic by hand.
            $amounts = Allocator::allocate($signed, array_column($splits, 'shareBp'));

            // Replace this axis's previous allocation atomically.
            JournalEntryLineAnalytic::query()
                ->where('journal_entry_line_id', $line->getKey())
                ->where('analytic_axis_id', $axis->getKey())
                ->delete();

            $rows = [];

            foreach ($splits as $index => $split) {
                $rows[] = JournalEntryLineAnalytic::query()->create([
                    'journal_entry_line_id' => $line->getKey(),
                    'analytic_axis_id' => $axis->getKey(),
                    'analytic_value_id' => $values[$index]->getKey(),
                    'amount' => $amounts[$index]->amount(),
                    'share_bp' => $split['shareBp'],
                ]);
            }

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Accounting',
                auditableType: JournalEntryLine::class,
                auditableId: (int) $line->getKey(),
                after: [
                    'axis' => $axis->code,
                    'splits' => array_map(
                        static fn (JournalEntryLineAnalytic $row): array => [
                            'analytic_value_id' => $row->analytic_value_id,
                            'amount' => $row->amount,
                            'share_bp' => $row->share_bp,
                        ],
                        $rows,
                    ),
                ],
                actor: $actor,
            );

            return $rows;
        });
    }
}
