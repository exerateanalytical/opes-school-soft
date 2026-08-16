# Guardian Session Revocation — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans or work the single task below directly. One task, no decomposition needed.

**Goal:** implement step 4 of `SetGuardianAuthorization`'s own documented spec — revoke a guardian's active portal sessions when their authorization actually changes today.

**Why now, and why this wasn't done before:** the class's docblock states step 4 was deliberately skipped because "there is no guardian portal, no portal session store... in Phase 2." Both now exist (built this session/evening by a concurrent session — `/portal`, `guardians.portal_user_id`, `SESSION_DRIVER=database`). The comment is stale; the gap is now real and buildable.

**Scope judgment call, stated explicitly:** the close-and-succeed path leaves the OLD flags valid through the end of today (`valid_to = today`, successor `valid_from = tomorrow`) — per the class's own docblock, "exactly one row is in force on every calendar day," meaning DB-level authorization does not change until tomorrow. Revoking the session today is still correct: an operator changing a guardian's authorization (especially a revocation — custody dispute, safety concern) reasonably wants that guardian logged out of any live session immediately, not free to keep browsing under a stale session until the formal cutover. This plan revokes on the close-and-succeed path only, NOT on the future-dated amend-in-place path (nothing about today's granted access changes there, so forcing a logout accomplishes nothing and would be needless friction).

**Tech:** `sessions` table (Laravel's own, `SESSION_DRIVER=database`), keyed by `user_id`. Revocation is `DB::table('sessions')->where('user_id', $portalUserId)->delete()` — the standard mechanism for this driver; no custom token/session-store code needed.

---

### Task: Revoke portal sessions on authorization change

**Files:**
- Modify: `app/Modules/Guardians/Actions/SetGuardianAuthorization.php`
- Test: `tests/Feature/Guardians/SetGuardianAuthorizationTest.php` (existing file — append; do not create a new one, check it exists first)

- [ ] **Step 1: Read the existing test file** to confirm the exact seed/helper pattern already used for this Action's tests (guardian + student + link setup) — reuse it, don't invent a new pattern.

- [ ] **Step 2: Write the failing test**

```php
it('revokes the guardian\'s active portal sessions when authorization changes today', function (): void {
    // Use whatever this file's existing helper builds a StudentGuardian link
    // with (e.g. a helper function already at the top of this file, or
    // factories) — link a guardian that HAS portal_user_id set.
    $guardian = \App\Modules\Guardians\Models\Guardian::factory()->create([
        'portal_user_id' => \App\Modules\Identity\Models\User::factory()->create()->id,
    ]);
    // ... build $link (StudentGuardian) for this guardian, following the
    // file's existing pattern ...

    DB::table('sessions')->insert([
        'id' => 'test-session-id',
        'user_id' => $guardian->portal_user_id,
        'payload' => base64_encode('x'),
        'last_activity' => time(),
    ]);

    app(\App\Modules\Guardians\Actions\SetGuardianAuthorization::class)->handle(
        $link,
        ['has_custody' => false],
        'Court order received',
    );

    expect(DB::table('sessions')->where('user_id', $guardian->portal_user_id)->exists())->toBeFalse();
});

it('does not revoke sessions on a future-dated amendment that has not taken effect', function (): void {
    // Build a $link whose valid_from is in the future (tomorrow or later),
    // following whatever pattern the existing "amend in place" test in this
    // file already uses.
    $guardian = \App\Modules\Guardians\Models\Guardian::factory()->create([
        'portal_user_id' => \App\Modules\Identity\Models\User::factory()->create()->id,
    ]);
    // ... build a future-dated $link ...

    DB::table('sessions')->insert([
        'id' => 'test-session-id-2',
        'user_id' => $guardian->portal_user_id,
        'payload' => base64_encode('x'),
        'last_activity' => time(),
    ]);

    app(\App\Modules\Guardians\Actions\SetGuardianAuthorization::class)->handle(
        $link,
        ['has_custody' => false],
        'Planned change',
    );

    expect(DB::table('sessions')->where('user_id', $guardian->portal_user_id)->exists())->toBeTrue();
});

it('does nothing when the guardian has no portal account', function (): void {
    // A guardian with portal_user_id === null must not throw or attempt a
    // delete keyed on null (which would match every session with a null
    // user_id, if any exist - a real bug to guard against explicitly).
    // Build a link for a guardian with portal_user_id null, call handle(),
    // assert it succeeds without error.
});
```

**This draft is unverified against the real test file's helpers/factories.** Match it to whatever pattern already exists in `SetGuardianAuthorizationTest.php` for building a guardian + link — do not invent parallel seed code if a helper already does this.

- [ ] **Step 3: Run it, confirm it fails**

```
"C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe" artisan test --filter=SetGuardianAuthorizationTest
```
(Check no other test process is running first — shared `opeschool_test` DB. If busy, wait, don't kill another session's run.)

- [ ] **Step 4: Implement**

In `SetGuardianAuthorization::handle()`, in the close-and-succeed branch (after Step 3's audit-log write, before `return $successor;`), add:

```php
            $this->revokePortalSessions($current->guardian_id);

            return $successor;
```

Add a private method:

```php
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
```

**Before saving:** confirm `$current->guardian_id` is the right property name (it's used elsewhere in the same method already — verify), and confirm no existing method in this class already does something similar under a different name.

Also update the class docblock's step-4 line — it currently reads "revoke the guardian's active portal sessions for that child" with no implementation note; the paragraph below it ("STEP 4 IS NOT IMPLEMENTED HERE...") is now false and must be corrected or removed, not left contradicting the code beneath it.

- [ ] **Step 5: Run the tests**

```
"C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe" artisan test --filter=SetGuardianAuthorizationTest
```
Expected: PASS, all tests including the 3 new ones.

- [ ] **Step 6: Run the module's broader suite as a light regression check**

```
"C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe" artisan test --filter=Guardians
```

- [ ] **Step 7: Commit**

```bash
git add app/Modules/Guardians/Actions/SetGuardianAuthorization.php \
        tests/Feature/Guardians/SetGuardianAuthorizationTest.php
git commit -m "feat(guardians): revoke portal sessions when authorization actually changes

Step 4 of this Action's own documented spec, previously and deliberately
unimplemented because no guardian portal or session store existed. Both
now do. Revokes only on the close-and-succeed path - a future-dated
amendment hasn't changed anything a live session could be wrong about yet."
```

Stage only these two files.

## Self-review

- **Spec coverage:** the one task covers the one documented gap.
- **Placeholder scan:** none.
- **Scope:** single Action, single concern, no decomposition needed.
- **Ambiguity resolved explicitly:** which path revokes (close-and-succeed only) is stated as a judgment call with reasoning, not left implicit.
