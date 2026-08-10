<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Models;

use App\Modules\Notifications\Domain\NotificationKind;
use Illuminate\Database\Eloquent\Model;

/**
 * A platform notification (in-app bell + optionally pushed to a browser).
 *
 * No relation methods to another module's models: `subject_type`/
 * `subject_id` is a plain polymorphic pointer used only to build the `url`
 * at creation time, following the module-boundary convention
 * GuardianMeeting/ConductAssessment already established.
 *
 * @property int $id
 * @property int $user_id
 * @property NotificationKind $kind
 * @property string $title
 * @property \Illuminate\Support\Carbon|null $read_at
 */
final class Notification extends Model
{
    protected $table = 'notifications';

    /** @var list<string> */
    protected $fillable = [
        'user_id', 'kind', 'title', 'body', 'url',
        'subject_type', 'subject_id', 'read_at', 'pushed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => NotificationKind::class,
            'read_at' => 'datetime',
            'pushed_at' => 'datetime',
        ];
    }
}
