<?php

declare(strict_types=1);

namespace App\Modules\Communication\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One user's membership in a thread. `last_read_at` per participant is what
 * makes an unread badge possible without scanning every message on render.
 *
 * @property int $id
 * @property int $user_id
 * @property \Illuminate\Support\Carbon|null $last_read_at
 */
final class MessageThreadParticipant extends Model
{
    protected $table = 'message_thread_participants';

    /** @var list<string> */
    protected $fillable = [
        'message_thread_id', 'user_id', 'last_read_at', 'last_read_message_id',
        'is_muted', 'added_at', 'removed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_read_at' => 'datetime',
            'is_muted' => 'boolean',
            'added_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }
}
