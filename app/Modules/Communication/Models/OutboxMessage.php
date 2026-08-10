<?php

declare(strict_types=1);

namespace App\Modules\Communication\Models;

use App\Modules\Communication\Domain\MessageChannel;
use App\Modules\Communication\Domain\MessageStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * `outbox_messages` (2026_08_09_300004) - the queue that makes 00-core 3's
 * "degrades to a queued outbox, never a blocking error" true.
 *
 * `subject_type` / `subject_id` say who the message is ABOUT (a guardian, a
 * staff member) and are deliberately NOT a polymorphic relation: resolving
 * them would mean importing other modules' Models (00-core 6.2). They are a
 * free-string tag plus an id, and a screen that wants a name reads it with
 * DB::table.
 *
 * @property int $id
 * @property MessageChannel $channel
 * @property string $recipient
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property int|null $message_template_id
 * @property string $language
 * @property string|null $subject_line
 * @property string $body
 * @property array<string, mixed>|null $payload
 * @property MessageStatus $status
 * @property int $attempts
 * @property Carbon $queued_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $failed_at
 * @property string|null $failure_reason
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class OutboxMessage extends Model
{
    /**
     * How many delivery attempts a single message gets before the office has
     * to intervene. A cap, not a preference: an outbox that retries a bad
     * number forever burns gateway credit the school pays for.
     */
    public const MAX_ATTEMPTS = 5;

    /** @var list<string> */
    protected $fillable = [
        'channel', 'recipient', 'subject_type', 'subject_id',
        'message_template_id', 'language', 'subject_line', 'body', 'payload',
        'status', 'attempts', 'queued_at', 'sent_at', 'failed_at',
        'failure_reason', 'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => MessageChannel::class,
            'status' => MessageStatus::class,
            'subject_id' => 'integer',
            'message_template_id' => 'integer',
            'attempts' => 'integer',
            'created_by' => 'integer',
            'payload' => 'array',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<MessageTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(MessageTemplate::class, 'message_template_id');
    }

    /**
     * Rows the dispatcher may pick up: queued and still under the cap.
     *
     * @param  Builder<OutboxMessage>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', MessageStatus::Queued->value)
            ->where('attempts', '<', self::MAX_ATTEMPTS);
    }

    public function hasAttemptsLeft(): bool
    {
        return $this->attempts < self::MAX_ATTEMPTS;
    }
}
