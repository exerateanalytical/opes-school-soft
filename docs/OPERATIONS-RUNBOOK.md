# Operations runbook

What has to be running, and what to do when the health screen is not all green.

Check the current state at any time:

```
php artisan opes:health
```

That command is the authority. Everything below exists to make it green *honestly* —
a check that has been argued into passing without the underlying protection in place
is worse than a red one, because it stops anybody looking.

---

## 1. The scheduler must be running

**This is the one that silently disables everything else.** The nightly backup, the
audit-chain verification, the ledger sweep, the outbox and the webhook deliveries are
all scheduled tasks. If nothing runs the scheduler, none of them happen, nothing
errors, and nothing appears in a log anyone reads. The failure mode is silence.

The `Background tasks` check exists to break that silence: the scheduler writes a
heartbeat every five minutes, and the check reports how long ago that last happened.

### Windows

Run once, as Administrator, substituting the real paths:

```
schtasks /Create /TN "OPES Scheduler" /SC ONSTART /RU SYSTEM /RL HIGHEST /F /TR "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe C:\laragon\www\opeschool-cloud\artisan schedule:work"
```

`/SC ONSTART` with `/RU SYSTEM` is what makes it survive a reboot with nobody logged
in — a school office machine is switched off every evening, and a scheduler that only
runs while a particular person is logged in will miss exactly the nights that matter.

Start it now without waiting for a reboot:

```
schtasks /Run /TN "OPES Scheduler"
```

Confirm it is up:

```
schtasks /Query /TN "OPES Scheduler"
```

### Linux

Either a cron entry:

```
* * * * * cd /var/www/opeschool-cloud && php artisan schedule:run >> /dev/null 2>&1
```

or a systemd unit running `php artisan schedule:work` with `Restart=always`.

### Verifying

Within five minutes of starting it:

```
php artisan opes:health
```

`Background tasks` should read *Running; last reported in a minute ago.* If it still
says the runner has never reported in, the scheduler process is not actually alive —
check that the PHP path in the task definition is correct.

---

## 2. Backups

Taken nightly at 01:00 by the scheduler, verified at 03:00, pruned at 03:30, and a
restore drill runs on the 1st of each month at 04:00.

To take one by hand:

```
php artisan opes:backup:run
```

**A backup is not a backup until it has been restored.** `opes:backup:run` finishing
cleanly does not mean the file will load: charset mismatches, DEFINER clauses and
missing routines all produce clean-looking dumps that fail on restore. Prove it:

```
php artisan opes:backup:drill
```

The drill restores the newest healthy backup into a temporary database, checks the
tables, the row counts and the ledger balances, and then drops it. It never touches
live data.

Verification catches corruption between backups:

```
php artisan opes:backup:verify
```

A file whose checksum no longer matches is marked `corrupt` and stops counting as a
restorable backup — the `Last backup` check only looks at healthy ones. A zero-byte
dump from a failed run is caught this way.

---

## 3. The second backup target

`Backup target` stays amber until backups are written somewhere that a single disk
failure cannot take with it:

```
OPES_BACKUP_SECOND_TARGET=D:\opes-offsite
```

Note the value is **unquoted**. phpdotenv treats a backslash inside a double-quoted
value as an escape sequence and aborts boot on Windows paths.

Pointing this at another folder on the same disk does not clear the warning, and is
not meant to: the check compares the two volumes, and reports *Both backup locations
are on the same disk* when they match. A copy beside the original is lost with the
original. Use separate hardware — a USB drive rotated weekly is enough.

If the volume cannot be determined — an unplugged rotation drive, a UNC share — the
check accepts the configuration rather than reporting a fault it cannot substantiate.

---

## 4. What each red actually costs

| Check | If it is red |
|---|---|
| `Background tasks` | Nothing scheduled is running, including the nightly backup. Everything below follows from this one. |
| `Last backup` | A disk failure loses every record the school has since the last good copy. |
| `Restore drill` | Backups exist but none is known to load. This is only discovered when one is needed. |
| `Audit log` | The audit chain no longer verifies; entries may have been altered. |
| `Ledger integrity` | An accounting invariant is broken. Investigate before issuing further receipts. |
