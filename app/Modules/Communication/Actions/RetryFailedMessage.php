<?php

declare(strict_types=1);

namespace App\Modules\Communication\Actions;

use App\Modules\Communication\Domain\MessageStatus;
use App\Modules\Communication\Models\OutboxMessage;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Puts a failed (or `disabled`) message back on the queue.
 *
 * Two rules the office cannot argue with:
 *  - a SENT message is never re-queued; re-sending a bulletin notice to a
 *    parent because a clerk clicked twice is a real harm, so it refuses;
 *  - the attempt cap holds. Beyond OutboxMessage::MAX_ATTEMPTS the row can
 *    only be revived by `resetAttempts`, which is a deliberate,
 *    separately-named decision rather than a button that quietly loops.
 *
 * `disabled` rows are the interesting case: they are what 00-core 3
 * promised - the school configures a channel months later, retries, and the
 * backlog drains.
 */
final class RetryFailedMessage
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    public function handle(int $messageId, Actor $actor, bool $resetAttempts = false): OutboxMessage
    {
        Gate::authorize(Permission::CommunicationSend->value);

        return DB::transaction(function () use ($messageId, $actor, $resetAttempts): OutboxMessage {
            /** @var OutboxMessage $message */
            $message = OutboxMessage::query()->lockForUpdate()->findOrFail($messageId);

            if ($message->status === MessageStatus::Sent) {
                throw new DomainException('This message was already delivered; it will not be sent again.');
            }

            if ($message->status === MessageStatus::Queued) {
                throw new DomainException('This message is already waiting in the queue.');
            }

            $before = [
                'status' => $message->status->value,
                'attempts' => $message->attempts,
                'failure_reason' => $message->failure_reason,
            ];

            if ($resetAttempts) {
                $message->attempts = 0;
            }

            if (! $message->hasAttemptsLeft()) {
                throw new DomainException(
                    'This message has used all '.OutboxMessage::MAX_ATTEMPTS
                    .' delivery attempts. Clear the attempt count to force another try.'
                );
            }

            $message->status = MessageStatus::Queued;
            $message->queued_at = Carbon::now();
            $message->failed_at = null;
            $message->failure_reason = null;
            $message->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Communication',
                auditableType: OutboxMessage::class,
                auditableId: (int) $message->getKey(),
                before: $before,
                after: [
                    'status' => $message->status->value,
                    'attempts' => $message->attempts,
                    'requeued' => true,
                ],
                actor: $actor,
            );

            return $message;
        });
    }

    /**
     * Bulk requeue of everything currently failed/disabled - the "the
     * gateway is back" button. Returns how many rows moved.
     */
    public function all(Actor $actor, bool $resetAttempts = false): int
    {
        Gate::authorize(Permission::CommunicationSend->value);

        $ids = OutboxMessage::query()
            ->whereIn('status', [MessageStatus::Failed->value, MessageStatus::Disabled->value])
            ->when(! $resetAttempts, fn ($q) => $q->where('attempts', '<', OutboxMessage::MAX_ATTEMPTS))
            ->orderBy('id')
            ->pluck('id');

        $moved = 0;

        foreach ($ids as $id) {
            try {
                $this->handle((int) $id, $actor, $resetAttempts);
                $moved++;
            } catch (DomainException) {
                // Raced or capped - the next run will show it still failed.
                continue;
            }
        }

        return $moved;
    }
}
