<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Actions;

use App\Modules\Guardians\Domain\GuardianCapability;
use App\Modules\Guardians\Policies\GuardianPortalPolicy;
use App\Modules\Welfare\Actions\AcknowledgeSanction;
use App\Support\Audit\Actor;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Row 21 of the 7.5 matrix: a guardian acknowledging a sanction against their
 * own child, from the portal or the mobile app.
 *
 * This is the "portal-scoped wrapper" Welfare\Actions\AcknowledgeSanction's
 * docblock has been promising since Phase 12. It contributes exactly two
 * things and writes nothing itself:
 *
 *   1. the matrix decision - row 21 for THIS child, which needs `has_custody`;
 *   2. the ownership conjunct the matrix does not own - the sanction must hang
 *      off a case belonging to this child AND that case must be
 *      `visibility = 'guardian'`. Without the second, a guardian holding row 21
 *      could acknowledge a sanction on an `internal` case they are not even
 *      allowed to know exists, and the acknowledgement would confirm it.
 *
 * The stamp, the audit entry and the refusal-of-a-repeat all stay in Welfare,
 * which owns discipline. Crossing to another module through its Actions is the
 * sanctioned door (00-core §6.2 rule 2); crossing through its Models is not,
 * and this class touches no Welfare model.
 */
final class AcknowledgeSanctionAsGuardian
{
    public function __construct(
        private readonly GuardianPortalPolicy $policy,
        private readonly AcknowledgeSanction $writer,
    ) {
    }

    public function handle(int $studentId, int $sanctionId, ?Actor $actor = null): void
    {
        // Row 32 first: an unlinked child yields "no such thing", never a hint.
        if (! $this->policy->allows(GuardianCapability::R01ViewChildIdentity, $studentId)) {
            throw new NotFoundHttpException();
        }

        $this->policy->authorize(GuardianCapability::R21AcknowledgeSanction, $studentId);

        $belongs = DB::table('discipline_sanctions as s')
            ->join('discipline_cases as c', 'c.id', '=', 's.discipline_case_id')
            ->where('s.id', $sanctionId)
            ->where('c.student_id', $studentId)
            // The same conjunct the read path applies. A sanction a parent may
            // not SEE is not a sanction a parent may SIGN.
            ->where('c.visibility', 'guardian')
            ->exists();

        if (! $belongs) {
            throw new NotFoundHttpException();
        }

        $this->writer->handleAuthorized($sanctionId, $actor);
    }
}
