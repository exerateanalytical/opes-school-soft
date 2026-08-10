<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions\YearEnd;

use App\Modules\Accounting\Domain\YearEndItemStatus;
use App\Modules\Accounting\Models\YearEndChecklistItem;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/02-accounting.md §17.3 YE-2 - the waiver.
 *
 * "An item may only be `waived` with a reason >= 20 characters, by a user
 * holding `accounting.waive_year_end_step`." The length rule is here; the
 * NOT-NULL half is a CHECK in 2026_08_10_360001.
 *
 * PERMISSION NOTE: this repo's `Permission` enum has no
 * `accounting.waive_year_end_step` case, and inventing one silently would
 * grant nobody the ability (it needs a role seed and a central wiring pass).
 * The Action therefore gates on `ledger.configure` - the strongest existing
 * accounting ability, held by exactly the people who would sign a close -
 * and the dedicated permission is reported for the central wiring pass
 * rather than half-created here.
 *
 * A waiver is a RECORDED DECISION, not a shortcut: §17.3 requires the waiver
 * list to be printed on the closing report, which is why the reason, the
 * waiver's author and its timestamp are all columns, and why nothing in this
 * module ever silently un-waives an item.
 */
final class WaiveYearEndChecklistItem
{
    public const PERMISSION = Permission::LedgerConfigure->value;

    public const MINIMUM_REASON_LENGTH = 20;

    public function __construct(private readonly WriteAuditEntry $audit) {}

    public function handle(int $itemId, string $reason, Actor $actor): YearEndChecklistItem
    {
        Gate::authorize(self::PERMISSION);

        $reason = trim($reason);

        if (mb_strlen($reason) < self::MINIMUM_REASON_LENGTH) {
            throw new DomainException(sprintf(
                'A waiver needs a reason of at least %d characters (YE-2). An auditor reading "n/a" learns nothing; write what was actually done instead of this step.',
                self::MINIMUM_REASON_LENGTH,
            ));
        }

        if ($actor->id === null) {
            throw new DomainException('A waiver must name the user who granted it.');
        }

        return DB::transaction(function () use ($itemId, $reason, $actor): YearEndChecklistItem {
            /** @var YearEndChecklistItem $item */
            $item = YearEndChecklistItem::query()->whereKey($itemId)->lockForUpdate()->firstOrFail();

            if ($item->status === YearEndItemStatus::Completed) {
                throw new DomainException(sprintf(
                    'Step %d (%s) is already completed; there is nothing to waive.',
                    $item->sequence,
                    $item->title,
                ));
            }

            if ($item->status === YearEndItemStatus::Waived) {
                throw new DomainException(sprintf(
                    'Step %d (%s) is already waived: "%s".',
                    $item->sequence,
                    $item->title,
                    (string) $item->waiver_reason,
                ));
            }

            $item->forceFill([
                'status' => YearEndItemStatus::Waived->value,
                'waiver_reason' => $reason,
                'waived_by' => $actor->id,
                'waived_at' => now(),
            ])->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Accounting',
                auditableType: YearEndChecklistItem::class,
                auditableId: (int) $item->getKey(),
                after: [
                    'status' => YearEndItemStatus::Waived->value,
                    'code' => $item->code,
                    'waiver_reason' => $reason,
                ],
                actor: $actor,
            );

            return $item;
        });
    }
}
