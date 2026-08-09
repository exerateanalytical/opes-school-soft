<?php

declare(strict_types=1);

namespace App\Modules\Assets\Actions;

use App\Modules\Accounting\Actions\PostFromEvent;
use App\Modules\Accounting\Domain\PostingEvent;
use App\Modules\Assets\Domain\AssetPermission;
use App\Modules\Assets\Domain\SubsidyStatus;
use App\Modules\Assets\Models\InvestmentSubsidy;
use App\Modules\Assets\Models\InvestmentSubsidyRelease;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * 06-assets-stores.md §6.4 - grant clawback: the donor recalls the grant.
 * The UNRELEASED balance (granted − Σ quote-parts released) reverses out
 * of 14 against a liability to the donor - `asset.subsidy.clawed_back`,
 * Dr class-14 / Cr the donor liability, partner-stamped. Already-released
 * quote-parts are NOT reversed: they matched depreciation that genuinely
 * happened. status → clawed_back stops all future releases.
 */
final class ClawBackSubsidy
{
    public function __construct(
        private readonly PostFromEvent $post,
        private readonly WriteAuditEntry $audit,
    ) {}

    public function handle(
        int $subsidyId,
        int $liabilityAccountId,
        string $date,
        string $reason,
        Actor $actor,
    ): InvestmentSubsidy {
        Gate::authorize(AssetPermission::MANAGE);

        return DB::transaction(function () use ($subsidyId, $liabilityAccountId, $date, $reason, $actor): InvestmentSubsidy {
            /** @var InvestmentSubsidy|null $subsidy */
            $subsidy = InvestmentSubsidy::query()->lockForUpdate()->find($subsidyId);

            if ($subsidy === null) {
                throw new DomainException("Investment subsidy {$subsidyId} does not exist.");
            }

            if ($subsidy->status !== SubsidyStatus::Active) {
                throw new DomainException(
                    "Subsidy '{$subsidy->reference}' is {$subsidy->status->value}; only an active subsidy can be clawed back."
                );
            }

            if (trim($reason) === '') {
                throw new DomainException('A clawback requires a reason.');
            }

            $released = (int) InvestmentSubsidyRelease::query()
                ->where('investment_subsidy_id', $subsidyId)
                ->sum('amount');

            $unreleased = $subsidy->granted_amount - $released;

            if ($unreleased <= 0) {
                throw new DomainException(
                    "Subsidy '{$subsidy->reference}' has no unreleased balance left to claw back."
                );
            }

            $entry = $this->post->handle(
                PostingEvent::AssetSubsidyClawedBack->value,
                [
                    'subsidy' => [
                        'amount' => $unreleased,
                        'reference' => $subsidy->reference,
                        'partner' => ['type' => 'supplier', 'id' => $subsidy->donor_partner_id],
                        'subsidy_account_id' => $subsidy->subsidy_account_id,
                        'counterpart_account_id' => $liabilityAccountId,
                    ],
                ],
                $date,
                $actor,
                $subsidy->reference,
            );

            $affected = InvestmentSubsidy::query()
                ->whereKey($subsidyId)
                ->where('status', SubsidyStatus::Active->value)
                ->update(['status' => SubsidyStatus::ClawedBack->value]);

            if ($affected !== 1) {
                throw new DomainException(
                    "Subsidy '{$subsidy->reference}' changed state concurrently; clawback aborted."
                );
            }

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Assets',
                auditableType: InvestmentSubsidy::class,
                auditableId: $subsidyId,
                before: ['status' => SubsidyStatus::Active->value],
                after: [
                    'status' => SubsidyStatus::ClawedBack->value,
                    'unreleased_amount' => $unreleased,
                    'reason' => $reason,
                    'journal_entry_id' => (int) $entry->getKey(),
                ],
                actor: $actor,
            );

            return $subsidy->refresh();
        });
    }
}
