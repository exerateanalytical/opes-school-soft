<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Support;

use App\Modules\Guardians\Models\Guardian;
use App\Modules\Guardians\Models\StudentGuardian;
use App\Support\Clock\BusinessDate;
use Illuminate\Support\Facades\DB;

/**
 * The resolved portal principal for ONE request: which Guardian is behind the
 * authenticated user, and which business date every link-validity check in
 * this request must use.
 *
 * 07-students 7.3 requires the date to be evaluated "once at transaction
 * start and passed down - not re-evaluated per query, so a request spanning
 * midnight cannot see two different answers". This object is that single
 * evaluation: EnsureGuardianPortal builds it at the top of the request and
 * binds it into the container, and every policy decision afterwards reads
 * `asOf` from here rather than asking the clock again.
 *
 * Livewire's subsequent wire requests hit /livewire/update, which does not
 * pass through the portal route group - so `current()` can also rebuild the
 * context from the authenticated user, applying the SAME preconditions the
 * middleware applies (active user, active guardian). A component method
 * invoked over the wire therefore gets an equally-vetted context or null,
 * never a weaker one.
 */
final class PortalContext
{
    /** @var array<int, StudentGuardian|null> */
    private array $links = [];

    public function __construct(
        public readonly Guardian $guardian,
        public readonly string $asOf,
    ) {}

    /**
     * The context for the current request, memoised in the container. Null
     * when the authenticated user is not an active guardian-portal principal
     * - which callers must treat as deny, not as "fall back to staff rules".
     */
    public static function current(): ?self
    {
        $app = app();

        if ($app->bound(self::class)) {
            return $app->make(self::class);
        }

        $context = self::resolveFromAuth();

        if ($context !== null) {
            $app->instance(self::class, $context);
        }

        return $context;
    }

    /**
     * Build the context from the session's user, enforcing the conjunctive
     * preconditions 7.5 attaches to every grant that are not per-link:
     * `User.status = 'active'` and `Guardian.status = 'active'` (plus not
     * archived). Per-link validity is enforced in linkFor().
     */
    public static function resolveFromAuth(): ?self
    {
        $userId = auth()->id();

        if ($userId === null) {
            return null;
        }

        // Queried rather than read off the Authenticatable contract: the
        // contract has no status accessor, and Guardians must not import
        // Identity's User model (tests/Architecture/ModuleBoundaryTest.php).
        $status = DB::table('users')->where('id', $userId)->value('status');

        if ($status !== 'active') {
            return null;
        }

        $guardian = Guardian::query()
            ->where('portal_user_id', $userId)
            ->where('is_archived', false)
            ->first();

        if ($guardian === null || ! $guardian->isActive()) {
            return null;
        }

        return new self($guardian, BusinessDate::today());
    }

    /**
     * The guardian's currently-valid link to $studentId, or null when none
     * exists - which is 7.5 row 32 territory: anything concerning a child
     * they are not linked to is denied.
     *
     * Memoised per student so a screen that checks five capabilities for one
     * child resolves the link once. The guardian relation is set from the
     * already-loaded row so authorizationFlags() never re-queries it.
     */
    public function linkFor(int $studentId): ?StudentGuardian
    {
        if (! array_key_exists($studentId, $this->links)) {
            $link = StudentGuardian::query()
                ->where('guardian_id', $this->guardian->getKey())
                ->where('student_id', $studentId)
                ->validOn($this->asOf)
                ->orderByDesc('valid_from')
                ->first();

            $link?->setRelation('guardian', $this->guardian);

            $this->links[$studentId] = $link;
        }

        return $this->links[$studentId];
    }

    /**
     * Every currently-valid link this guardian holds, for the capabilities
     * 7.5 grants on "any valid link" without naming a child (rows 16, 26, 29)
     * and for the children dashboard (row 1 per child).
     *
     * @return list<StudentGuardian>
     */
    public function validLinks(): array
    {
        $links = StudentGuardian::query()
            ->where('guardian_id', $this->guardian->getKey())
            ->validOn($this->asOf)
            ->orderBy('student_id')
            ->get();

        $result = [];

        foreach ($links as $link) {
            $link->setRelation('guardian', $this->guardian);
            $this->links[$link->student_id] = $link;
            $result[] = $link;
        }

        return $result;
    }
}
