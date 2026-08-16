<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Actions;

use App\Modules\Guardians\Models\StudentGuardian;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use App\Support\Clock\BusinessDate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/specs/07-students.md 7.6 - change an authorization flag.
 *
 * The spec's four steps, in order:
 *
 *   1. close the current row with `valid_to = business_date()` and a mandatory
 *      `revocation_reason`;
 *   2. insert a successor with `valid_from = business_date() + 1 day` and the
 *      new flags;
 *   3. write AuditLog with the full BEFORE/AFTER flag set;
 *   4. revoke the guardian's active portal sessions for that child.
 *
 * Step 4 is implemented via the `sessions` table (`SESSION_DRIVER=database`),
 * keyed by `user_id`: deleting the guardian's portal user's row(s) is this
 * driver's standard force-logout mechanism. See revokePortalSessions() below.
 *
 * Why close-and-succeed rather than UPDATE: 7.2 forbids changing scope
 * retroactively. An in-place update would make last term's access look like it
 * had always been today's, and the audit row would be the only trace - which
 * is not enough when the question is "what could this person see on the day
 * the incident happened".
 *
 * The one-day gap between `valid_to = today` and `valid_from = tomorrow` is
 * NOT a gap. Under the 7.3 predicate `valid_to >= today` the closed row is
 * still valid today, and the successor's `valid_from > today` grants nothing
 * yet, so exactly one row is in force on every calendar day. Tested directly.
 *
 * PERMISSION, and a divergence worth naming: 7.6 asks for
 * `guardians.authorization.manage` (Registrar and Administrator; explicitly
 * not Front Desk, not Bursar). App\Modules\Identity\Domain\Permission carries
 * only `guardians.manage` in the Phase 0B set, and that enum is owned by
 * another module and another phase - a permission added here would be a rename
 * risk against seeded roles. This Action therefore gates on
 * `guardians.manage` today. The narrower right is a Phase-2-consolidation
 * follow-up, and until it exists Front Desk must not hold guardians.manage.
 *
 * Step 4 revokes only on the close-and-succeed path, not on the future-dated
 * amend-in-place path: an amendment whose valid_from is still ahead has not
 * granted or removed anything yet, so there is nothing a live session could
 * be wrong about.
 */
final class SetGuardianAuthorization
{
    /**
     * @param  array<string, bool>  $flags  any subset of
     *                                      StudentGuardian::AUDITED_SCOPE_COLUMNS; omitted flags carry over
     */
    public function handle(
        StudentGuardian $link,
        array $flags,
        string $reason,
        ?Actor $actor = null,
    ): StudentGuardian {
        Gate::authorize(Permission::GuardiansManage->value);

        $actor ??= $this->currentActor();

        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'revocation_reason' => 'A reason is required to change a guardian authorization.',
            ]);
        }

        $unknown = array_diff(array_keys($flags), StudentGuardian::AUDITED_SCOPE_COLUMNS);

        if ($unknown !== []) {
            // Fail loudly rather than ignore. A typo'd flag name that is
            // silently dropped reads to the operator as "the change was saved"
            // while the authorization is unchanged - the worst possible
            // outcome on this particular surface.
            throw ValidationException::withMessages([
                'flags' => 'Unknown authorization flag: '.implode(', ', $unknown).'.',
            ]);
        }

        // 7.3: one date, resolved once, used for the close AND the successor.
        $today = BusinessDate::today();
        $tomorrow = Carbon::parse($today)->addDay()->toDateString();

        return DB::transaction(function () use ($link, $flags, $reason, $today, $tomorrow, $actor): StudentGuardian {
            /** @var StudentGuardian|null $current */
            $current = StudentGuardian::query()->whereKey($link->getKey())->lockForUpdate()->first();

            if ($current === null) {
                throw ValidationException::withMessages(['link' => 'This guardian link no longer exists.']);
            }

            if ($current->valid_to !== null) {
                throw ValidationException::withMessages([
                    'link' => 'This link is already closed; authorizations are changed on the open link.',
                ]);
            }

            $before = $this->flagSnapshot($current);
            $after = array_merge($before, $flags);

            if ($after === $before) {
                // No successor for a no-op. Otherwise every accidental save
                // fragments the link history into rows that differ only by
                // date, and the "what changed and when" reading of the table -
                // its whole purpose - degrades to noise.
                throw ValidationException::withMessages([
                    'flags' => 'No authorization flag would change.',
                ]);
            }

            // 7.2 again, on the successor this time: is_primary implies
            // has_custody. Revoking custody from the primary guardian without
            // also demoting them is the shape this catches.
            if (($after['is_primary'] ?? false) && ! ($after['has_custody'] ?? false)) {
                throw ValidationException::withMessages([
                    'has_custody' => 'The primary guardian must also hold custody; demote them in the same change.',
                ]);
            }

            // A link whose valid_from is still in the future has granted
            // nothing to anybody (7.3, first clause), so there is no history
            // to preserve and nothing to close: amend it in place. Without
            // this branch, a second change on the same day would try to set
            // valid_to = today on a row starting tomorrow, which the
            // ck_sg_valid_range CHECK rejects - a correction made twice before
            // lunch would fail with a constraint error.
            if ($current->valid_from->toDateString() > $today) {
                foreach ($after as $flag => $value) {
                    $current->setAttribute($flag, $value);
                }

                $current->updated_by = $actor->id;
                $current->save();

                app(WriteAuditEntry::class)->handle(
                    action: AuditAction::Updated,
                    module: 'Guardians',
                    auditableType: StudentGuardian::class,
                    auditableId: (int) $current->getKey(),
                    before: array_merge($before, [
                        'student_id' => $current->student_id,
                        'guardian_id' => $current->guardian_id,
                        'valid_from' => $current->valid_from->toDateString(),
                        'valid_to' => null,
                    ]),
                    after: array_merge($after, [
                        'student_id' => $current->student_id,
                        'guardian_id' => $current->guardian_id,
                        'valid_from' => $current->valid_from->toDateString(),
                        'valid_to' => null,
                        'reason' => $reason,
                        'amended_before_taking_effect' => true,
                    ]),
                    actor: $actor,
                );

                return $current;
            }

            // Step 1 - close the current row.
            $current->setAttribute('valid_to', $today);
            $current->revocation_reason = $reason;
            $current->updated_by = $actor->id;
            $current->save();

            // Step 2 - the successor, effective tomorrow. Created only after
            // the predecessor is closed, so uq_primary_guardian (which indexes
            // is_primary AND valid_to IS NULL) sees one open primary at a
            // time and does not reject the school's own correction.
            $successor = StudentGuardian::query()->create(array_merge([
                'student_id' => $current->student_id,
                'guardian_id' => $current->guardian_id,
                'relationship' => $current->relationship,
                'relationship_other' => $current->relationship_other,
                'valid_from' => $tomorrow,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ], $after));

            // Step 3 - the FULL flag set both sides, not just the deltas. A
            // diff of two complete snapshots can be read years later without
            // replaying every intervening change to know what the other flags
            // were at the time.
            app(WriteAuditEntry::class)->handle(
                action: AuditAction::Updated,
                module: 'Guardians',
                auditableType: StudentGuardian::class,
                auditableId: (int) $current->getKey(),
                before: array_merge($before, [
                    'student_id' => $current->student_id,
                    'guardian_id' => $current->guardian_id,
                    'valid_from' => $current->valid_from->toDateString(),
                    'valid_to' => null,
                ]),
                after: array_merge($after, [
                    'student_id' => $current->student_id,
                    'guardian_id' => $current->guardian_id,
                    'valid_from' => $tomorrow,
                    'valid_to' => null,
                    'reason' => $reason,
                    'successor_link_id' => (int) $successor->getKey(),
                    'closed_link_valid_to' => $today,
                ]),
                actor: $actor,
            );

            $this->revokePortalSessions($current->guardian_id);

            return $successor;
        });
    }

    /**
     * @return array<string, bool>
     */
    private function flagSnapshot(StudentGuardian $link): array
    {
        $snapshot = [];

        foreach (StudentGuardian::AUDITED_SCOPE_COLUMNS as $flag) {
            $snapshot[$flag] = (bool) $link->getAttribute($flag);
        }

        return $snapshot;
    }

    private function currentActor(): Actor
    {
        return auth()->user()?->toAuditActor() ?? Actor::system();
    }

    /**
     * Step 4 of the class docblock's spec, now buildable: the guardian
     * portal and its database-backed session store both exist. Deleting the
     * row(s) from `sessions` is this driver's standard force-logout
     * mechanism - the next request from that browser finds no matching
     * session and is redirected to log in again, at which point the CURRENT
     * (still valid until midnight per 7.3) flags apply, same as before. The
     * point is not to change what they can see - it hasn't changed yet -
     * it is to end whatever they were already looking at immediately rather
     * than let a revocation-in-progress guardian keep browsing on a session
     * opened before the operator acted.
     *
     * Only called from the close-and-succeed path. A future-dated amendment
     * (valid_from still ahead) has not granted or removed anything yet, so
     * there is nothing to force an immediate end to.
     */
    private function revokePortalSessions(int $guardianId): void
    {
        $portalUserId = DB::table('guardians')->where('id', $guardianId)->value('portal_user_id');

        if ($portalUserId === null) {
            return;
        }

        DB::table('sessions')->where('user_id', $portalUserId)->delete();
    }
}
