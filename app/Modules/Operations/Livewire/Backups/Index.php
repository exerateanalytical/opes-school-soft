<?php

declare(strict_types=1);

namespace App\Modules\Operations\Livewire\Backups;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Operations\Actions\CollectHealth;
use App\Modules\Operations\Actions\CreateBackup;
use App\Modules\Operations\Actions\PruneBackups;
use App\Modules\Operations\Actions\RunRestoreDrill;
use App\Modules\Operations\Actions\VerifyBackup;
use App\Modules\Operations\Domain\BackupKind;
use App\Modules\Operations\Domain\HealthCheckResult;
use App\Modules\Operations\Domain\HealthStatus;
use App\Modules\Operations\Models\Backup;
use App\Modules\Operations\Models\RestoreDrill;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Throwable;

/**
 * Backup & Restore, docs/specs/08-operations.md §3 and 09-ui.md §8.12.
 *
 * The engine already existed and is untouched: this screen only calls the
 * shipped Actions (CreateBackup, VerifyBackup, PruneBackups, RunRestoreDrill),
 * which are the same code paths the five `opes:backup:*` / `opes:restore:*`
 * console commands drive. Until now every one of them was CLI-only, while the
 * dashboard told the operator "no backup has ever completed successfully" and
 * offered no button.
 *
 * RESTORE IS DELIBERATELY NOT EXECUTED FROM THIS SCREEN. `Permission::BackupRestore`
 * is withheld even from Administrator by Role::defaultPermissions(), and there
 * is no restore Action in the codebase - restore over the live database is a
 * CLI operation performed by someone who has read what they are about to
 * destroy. What this screen does is surface it honestly: it checks every
 * precondition (permission, a verified-healthy backup, a passed drill, a typed
 * confirmation) and then hands over the exact command. Adding an executing
 * button would be a one-click way to delete a school's year.
 *
 * Strings are literal English: lang/en|fr/opes.php is concurrently edited and
 * this screen adds no keys to it.
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    /** Typed confirmation for the restore gate. Must equal RESTORE_PHRASE. */
    public string $restoreConfirmation = '';

    public ?int $restoreBackupId = null;

    public ?string $restoreCommand = null;

    private const RESTORE_PHRASE = 'RESTORE';

    public function mount(): void
    {
        Gate::authorize(Permission::BackupRun->value);
    }

    public function runBackup(CreateBackup $create): void
    {
        Gate::authorize(Permission::BackupRun->value);

        try {
            $backup = $create->handle(BackupKind::Full);

            $backup->status()->isUsable()
                ? session()->flash('status', 'Backup #'.$backup->getKey().' completed.')
                : session()->flash('error', 'Backup failed: '.(string) $backup->failure_detail);
        } catch (Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function verify(int $backupId, VerifyBackup $verify): void
    {
        Gate::authorize(Permission::BackupRun->value);

        /** @var Backup|null $backup */
        $backup = Backup::query()->find($backupId);

        if ($backup === null) {
            session()->flash('error', 'That backup no longer exists.');

            return;
        }

        $verified = $verify->handle($backup);

        $verified->status()->isUsable()
            ? session()->flash('status', 'Backup #'.$verified->getKey().' verified against its checksum.')
            : session()->flash('error', 'Verification failed: '.(string) $verified->failure_detail);
    }

    /**
     * GFS pruning. The Action itself guarantees the last healthy backup
     * survives whatever the retention numbers say, so this screen adds no
     * second policy of its own.
     */
    public function prune(PruneBackups $prune): void
    {
        Gate::authorize(Permission::BackupRun->value);

        try {
            $deleted = $prune->handle();

            session()->flash('status', $deleted === 0
                ? 'Retention policy removed nothing; every backup on disk is still within policy.'
                : $deleted.' backup(s) pruned.');
        } catch (Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    /**
     * A drill restores into a THROWAWAY schema and drops it again - it never
     * touches the live database. This is the safe half of restore, and it is
     * the only control that turns "we have backups" into "we have proven we
     * can restore" (08-operations §3.6).
     */
    public function runDrill(RunRestoreDrill $drill): void
    {
        Gate::authorize(Permission::BackupRestore->value);

        try {
            $result = $drill->handle();

            $result->status === 'passed'
                ? session()->flash('status', 'Restore drill passed ('.$result->assertions_passed.' assertions).')
                : session()->flash('error', 'Restore drill failed: '.(string) $result->failure_detail);
        } catch (Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    /**
     * Check every restore precondition and, if they all hold, reveal the
     * command. Nothing is restored here - see the class docblock.
     */
    public function prepareRestore(int $backupId): void
    {
        Gate::authorize(Permission::BackupRestore->value);

        $this->restoreCommand = null;
        $this->restoreBackupId = $backupId;

        if ($this->restoreConfirmation !== self::RESTORE_PHRASE) {
            session()->flash('error', 'Type '.self::RESTORE_PHRASE.' exactly to unlock the restore instructions.');

            return;
        }

        /** @var Backup|null $backup */
        $backup = Backup::query()->find($backupId);

        if ($backup === null || ! $backup->status()->isUsable()) {
            session()->flash('error', 'Only a healthy backup may be restored from.');

            return;
        }

        if ($backup->verified_at === null) {
            session()->flash('error', 'Verify this backup against its checksum before restoring from it.');

            return;
        }

        if ($this->lastPassedDrill() === null) {
            session()->flash('error', 'No restore drill has ever passed; prove the restore works before running one for real.');

            return;
        }

        // There is no `opes:restore` command and this screen does not invent
        // one: the five shipped commands are opes:backup:{run,verify,prune,drill}
        // and opes:health. A restore over the live database is the mysql client
        // loading the dump, performed deliberately by a human at the console.
        $this->restoreCommand = sprintf(
            '"%s" --host=%s --user=%s -p %s < "%s"',
            (string) config('opes.mysql.client_binary'),
            (string) config('database.connections.mysql.host'),
            (string) config('database.connections.mysql.username'),
            (string) config('database.connections.mysql.database'),
            $backup->path,
        );

        session()->flash('status', 'Preconditions met. Run the command below from the server console.');
    }

    public function resetRestore(): void
    {
        $this->reset(['restoreConfirmation', 'restoreBackupId', 'restoreCommand']);
    }

    private function lastPassedDrill(): ?RestoreDrill
    {
        /** @var RestoreDrill|null $drill */
        $drill = RestoreDrill::query()
            ->where('status', 'passed')
            ->orderByDesc('completed_at')
            ->first();

        return $drill;
    }

    /**
     * The backup-related health lines, taken from CollectHealth rather than
     * re-derived: the "backups are written to one location only" warning the
     * dashboard already raises is BackupSecondTargetCheck, and there must be
     * exactly one implementation of it.
     *
     * @return list<HealthCheckResult>
     */
    private function backupHealth(CollectHealth $health): array
    {
        return array_values(array_filter(
            $health->handle(),
            static fn (HealthCheckResult $r): bool => $r->status !== HealthStatus::Ok
                && in_array($r->key, ['backup.recency', 'backup.second_target', 'drill.recency', 'disk.free'], true),
        ));
    }

    public function render(CollectHealth $health): mixed
    {
        return view('livewire.operations.backups.index', [
            'alerts' => $this->backupHealth($health),
            'canRestore' => Gate::allows(Permission::BackupRestore->value),
            'backupPath' => (string) config('opes.backup.path'),
            'secondTarget' => config('opes.backup.second_target'),
            'backups' => Backup::query()
                ->orderByDesc('started_at')
                ->orderByDesc('id')
                ->limit(50)
                ->get(),
            'drills' => RestoreDrill::query()
                ->orderByDesc('started_at')
                ->orderByDesc('id')
                ->limit(10)
                ->get(),
        ]);
    }
}
