<?php

declare(strict_types=1);

namespace App\Modules\Communication\Models;

use App\Modules\Communication\Domain\ThreadKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A conversation or announcement. Polymorphic on subject_type/subject_id so
 * a thread can be free-standing or anchored to something (a student), which
 * is what lets the SAME thread show on both a guardian's portal and the
 * relevant staff member's screen.
 *
 * @property int $id
 * @property string $title
 * @property ThreadKind $kind
 */
final class MessageThread extends Model
{
    protected $table = 'message_threads';

    /** @var list<string> */
    protected $fillable = [
        'subject_type', 'subject_id', 'title', 'kind',
        'created_by', 'last_message_at', 'is_archived',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ThreadKind::class,
            'last_message_at' => 'datetime',
            'is_archived' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'message_thread_id')->orderBy('created_at');
    }

    /**
     * @return HasMany<MessageThreadParticipant, $this>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(MessageThreadParticipant::class, 'message_thread_id');
    }
}
