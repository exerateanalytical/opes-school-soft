<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Models;

use App\Modules\Guardians\Domain\CommunicationChannel;
use App\Modules\Guardians\Domain\CommunicationDirection;
use App\Modules\Guardians\Domain\DeliveryStatus;
use Database\Factories\GuardianCommunicationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * docs/specs/07-students.md 7.8 - the per-guardian message log.
 *
 * Written by the Communication module, owned for DISPLAY here. This module
 * therefore ships no Action that inserts a row: doing so would put the sending
 * logic on the wrong side of the boundary.
 *
 * @property int $id
 * @property int $guardian_id
 * @property int|null $student_id
 * @property CommunicationChannel $channel
 * @property CommunicationDirection $direction
 * @property string|null $subject
 * @property string|null $body
 * @property Carbon|null $sent_at
 * @property DeliveryStatus $delivery_status
 * @property string|null $provider_reference
 * @property string|null $failure_reason
 * @property string|null $related_type
 * @property int|null $related_id
 * @property int|null $actor_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Guardian|null $guardian
 */
final class GuardianCommunication extends Model
{
    /** @use HasFactory<GuardianCommunicationFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'guardian_id',
        'student_id',
        'channel',
        'direction',
        'subject',
        'body',
        'sent_at',
        'delivery_status',
        'provider_reference',
        'failure_reason',
        'related_type',
        'related_id',
        'actor_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'guardian_id' => 'integer',
            'student_id' => 'integer',
            'channel' => CommunicationChannel::class,
            'direction' => CommunicationDirection::class,
            'sent_at' => 'datetime',
            'delivery_status' => DeliveryStatus::class,
            'related_id' => 'integer',
            'actor_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Guardian, $this>
     */
    public function guardian(): BelongsTo
    {
        return $this->belongsTo(Guardian::class);
    }

    protected static function newFactory(): GuardianCommunicationFactory
    {
        return GuardianCommunicationFactory::new();
    }
}
