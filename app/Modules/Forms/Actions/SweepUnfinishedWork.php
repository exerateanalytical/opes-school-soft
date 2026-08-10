<?php

declare(strict_types=1);

namespace App\Modules\Forms\Actions;

use App\Modules\Forms\Domain\DraftStatus;
use App\Modules\Forms\Models\FormDraft;
use App\Modules\Notifications\Actions\Notify;
use App\Modules\Notifications\Domain\NotificationKind;
use Illuminate\Support\Facades\DB;

/**
 * Finds held drafts nobody has returned to and notifies their owner.
 *
 * Runs on a schedule (routes/console.php), NOT on every request - a held
 * draft is meant to sit for a while (attend to someone else, come back
 * later), so "notify immediately" would be noise. Each draft notifies
 * ONCE per staleness window: a `notifications` row with matching
 * subject_type/subject_id already existing and unread is treated as "still
 * pending", so re-running the sweep does not re-notify every few minutes
 * for the same held item.
 */
final class SweepUnfinishedWork
{
    public function __construct(private readonly Notify $notify) {}

    public function handle(int $staleAfterMinutes = 60): int
    {
        $cutoff = now()->subMinutes($staleAfterMinutes);
        $notified = 0;

        FormDraft::query()
            ->where('status', DraftStatus::Held->value)
            ->where('updated_at', '<=', $cutoff)
            ->chunkById(200, function ($drafts) use (&$notified): void {
                foreach ($drafts as $draft) {
                    $alreadyNotified = DB::table('notifications')
                        ->where('subject_type', FormDraft::class)
                        ->where('subject_id', $draft->id)
                        ->whereNull('read_at')
                        ->exists();

                    if ($alreadyNotified) {
                        continue;
                    }

                    $this->notify->handle(
                        (int) $draft->user_id,
                        NotificationKind::UnfinishedWork,
                        __('opes.notifications.unfinished_work_title'),
                        $draft->hold_label ?? $draft->form_key,
                        null,
                        FormDraft::class,
                        (int) $draft->id,
                    );

                    $notified++;
                }
            });

        return $notified;
    }
}
