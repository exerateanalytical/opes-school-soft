<?php

declare(strict_types=1);

namespace App\Modules\Operations\Actions;

use App\Modules\Operations\Models\Backup;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\File;

/**
 * GFS retention, health-first.
 *
 * The invariant that matters: NEVER delete the last healthy backup, whatever
 * the retention numbers say. A policy that can remove your only good copy is
 * worse than no policy (08-operations 3.3).
 */
final class PruneBackups
{
    public function handle(): int
    {
        /** @var Collection<int, Backup> $all */
        $all = Backup::query()->orderByDesc('completed_at')->get();

        /** @var array<int, bool> $keep */
        $keep = [];

        $this->markNewestPerBucket($all, 'Y-m-d', (int) config('opes.backup.keep_daily'), $keep);
        $this->markNewestPerBucket($all, 'o-W', (int) config('opes.backup.keep_weekly'), $keep);
        $this->markNewestPerBucket($all, 'Y-m', (int) config('opes.backup.keep_monthly'), $keep);
        $this->markNewestPerBucket($all, 'Y', (int) config('opes.backup.keep_yearly'), $keep);

        // The floor: whatever the policy says, one healthy backup survives.
        $lastHealthy = $all->first(static fn (Backup $b): bool => $b->status()->isUsable());

        if ($lastHealthy !== null) {
            $keep[(int) $lastHealthy->getKey()] = true;
        }

        $deleted = 0;

        foreach ($all as $backup) {
            if (isset($keep[(int) $backup->getKey()])) {
                continue;
            }

            if (File::exists($backup->path)) {
                File::delete($backup->path);
            }

            $backup->delete();
            $deleted++;
        }

        return $deleted;
    }

    /**
     * @param  Collection<int, Backup>  $all
     * @param  array<int, bool>  $keep
     */
    private function markNewestPerBucket(Collection $all, string $format, int $limit, array &$keep): void
    {
        if ($limit <= 0) {
            return;
        }

        /** @var array<string, bool> $seen */
        $seen = [];

        foreach ($all as $backup) {
            if ($backup->completed_at === null) {
                continue;
            }

            // Corrupt copies are pruned before healthy ones of the same age.
            if (! $backup->status()->isUsable()) {
                continue;
            }

            $bucket = $backup->completed_at->format($format);

            if (isset($seen[$bucket])) {
                continue;
            }

            $seen[$bucket] = true;
            $keep[(int) $backup->getKey()] = true;

            if (count($seen) >= $limit) {
                return;
            }
        }
    }
}
