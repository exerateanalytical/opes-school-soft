<?php

declare(strict_types=1);

namespace App\Modules\Library\Actions\Concerns;

use App\Modules\Library\Domain\FineStatus;
use App\Modules\Library\Models\LibraryFine;
use App\Modules\Library\Models\LibraryMember;
use App\Modules\Library\Models\MembershipClass;
use DomainException;

/**
 * Shared standing checks for the circulation doors (§10.4/§10.9): the
 * blocking-fine rule reads the ASSESSMENT records still standing against
 * the member (assessed or invoiced-not-yet-settled; `library_fines.status`
 * transitions past `invoiced` are driven by events from Fees, §10.7).
 */
trait ChecksMemberStanding
{
    /** Sum of the member's unsettled fine exposure, net of waivers. */
    private function unpaidFineExposure(LibraryMember $member): int
    {
        return (int) LibraryFine::query()
            ->where('library_member_id', $member->getKey())
            ->whereIn('status', [FineStatus::Assessed->value, FineStatus::Invoiced->value])
            ->selectRaw('COALESCE(SUM(amount - waived_amount), 0) AS exposure')
            ->value('exposure');
    }

    private function assertNoBlockingFine(LibraryMember $member, MembershipClass $class, string $refusing): void
    {
        $exposure = $this->unpaidFineExposure($member);

        if ($exposure > $class->blocking_fine_threshold) {
            throw new DomainException(sprintf(
                '%s refused: member %s has %d FCFA of unpaid fines (threshold %d).',
                $refusing,
                $member->member_no,
                $exposure,
                $class->blocking_fine_threshold,
            ));
        }
    }
}
