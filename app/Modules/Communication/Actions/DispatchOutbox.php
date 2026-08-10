<?php

declare(strict_types=1);

namespace App\Modules\Communication\Actions;

use App\Modules\Communication\Domain\MessageStatus;
use App\Modules\Communication\Models\OutboxMessage;
use App\Modules\Communication\Support\DriverManager;
use App\Modules\Communication\Support\DriverResult;
use App\Modules\Communication\Support\MessageDriver;
use App\Modules\Identity\Domain\Permission;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Drains the outbox: takes queued rows and moves them through the state
 * machine using the configured driver.
 *
 * Idempotent and safe to re-run, which is not a nicety - this thing runs on
 * a schedule on a machine whose power is not guaranteed. Two guarantees:
 *
 *  1. A row is claimed inside a `lockForUpdate` transaction that re-reads
 *     its status; a second dispatcher racing the first finds the row no
 *     longer `queued` and skips it. No message is sent twice.
 *  2. The attempt counter is incremented as part of the claim, BEFORE the
 *     driver is called. A driver that hangs and gets killed therefore still
 *     burned its attempt, so a poisonous row cannot loop forever.
 *
 * A driver throwing is treated as a failed attempt, never as a crash of the
 * run: one bad number must not stop the other 300 fee reminders.
 */
final class DispatchOutbox
{
    public function __construct(private readonly DriverManager $drivers) {}

    /**
     * @param  int  $limit  how many rows one run may take (a batch, so a
     *                      scheduled run has a bounded worst case)
     * @return array{driver: string, considered: int, sent: int, failed: int, disabled: int, skipped: int}
     */
    public function handle(int $limit = 200, ?string $driverName = null, ?int $onlyId = null): array
    {
        // Gated for humans. An unattended run (the scheduler, the artisan
        // command) has no authenticated user to check, exactly as the
        // Operations backup Actions assume; the gate protects the button in
        // the UI, not the cron.
        if (auth()->check()) {
            Gate::authorize(Permission::CommunicationSend->value);
        }

        $driver = $this->drivers->resolve($driverName);

        $limit = max(1, $limit);

        $ids = OutboxMessage::query()
            ->pending()
            ->when($onlyId !== null, fn ($q) => $q->whereKey($onlyId))
            ->orderBy('queued_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $tally = [
            'driver' => $driver->name(),
            'considered' => $ids->count(),
            'sent' => 0,
            'failed' => 0,
            'disabled' => 0,
            'skipped' => 0,
        ];

        foreach ($ids as $id) {
            $message = $this->claim((int) $id);

            if ($message === null) {
                $tally['skipped']++;

                continue;
            }

            $result = $this->attempt($driver, $message);

            $this->settle($message, $result);

            match ($result->status) {
                MessageStatus::Sent => $tally['sent']++,
                MessageStatus::Failed => $tally['failed']++,
                MessageStatus::Disabled => $tally['disabled']++,
                MessageStatus::Queued => $tally['skipped']++,
            };
        }

        return $tally;
    }

    /**
     * Take ownership of one row, or return null if somebody else already
     * has. The attempt is spent here so a crash cannot be retried for free.
     */
    private function claim(int $id): ?OutboxMessage
    {
        return DB::transaction(function () use ($id): ?OutboxMessage {
            /** @var OutboxMessage|null $message */
            $message = OutboxMessage::query()->lockForUpdate()->find($id);

            if ($message === null) {
                return null;
            }

            if ($message->status !== MessageStatus::Queued || ! $message->hasAttemptsLeft()) {
                return null;
            }

            $message->attempts++;
            $message->save();

            return $message;
        });
    }

    private function attempt(MessageDriver $driver, OutboxMessage $message): DriverResult
    {
        try {
            return $driver->send($message);
        } catch (Throwable $e) {
            // A transport blowing up is data about the message, not an
            // outage of the dispatcher.
            return DriverResult::failed($e->getMessage());
        }
    }

    private function settle(OutboxMessage $message, DriverResult $result): void
    {
        $now = Carbon::now();

        $message->status = $result->status;

        if ($result->status === MessageStatus::Sent) {
            $message->sent_at = $now;
            $message->failed_at = null;
            $message->failure_reason = null;
        } else {
            $message->failed_at = $now;
            $message->failure_reason = $result->reason;
        }

        $message->save();
    }
}
