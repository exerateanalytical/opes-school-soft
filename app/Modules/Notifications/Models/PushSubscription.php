<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One browser/device a user has granted Web Push permission on.
 *
 * @property int $id
 * @property int $user_id
 * @property string $endpoint
 * @property string $p256dh
 * @property string $auth
 */
final class PushSubscription extends Model
{
    protected $table = 'push_subscriptions';

    /** @var list<string> */
    protected $fillable = [
        'user_id', 'endpoint', 'p256dh', 'auth', 'user_agent',
        'last_used_at', 'last_failed_at', 'last_failure_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['last_used_at' => 'datetime', 'last_failed_at' => 'datetime'];
    }
}
