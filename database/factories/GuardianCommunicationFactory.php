<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Guardians\Domain\CommunicationChannel;
use App\Modules\Guardians\Domain\CommunicationDirection;
use App\Modules\Guardians\Domain\DeliveryStatus;
use App\Modules\Guardians\Models\Guardian;
use App\Modules\Guardians\Models\GuardianCommunication;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GuardianCommunication>
 *
 * Defaults to `queued`, which 7.8 names as the normal steady state on a LAN
 * deployment with no connectivity - not a failure fixture.
 */
class GuardianCommunicationFactory extends Factory
{
    /** @var class-string<GuardianCommunication> */
    protected $model = GuardianCommunication::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'guardian_id' => Guardian::factory(),
            'student_id' => null,
            'channel' => CommunicationChannel::Sms,
            'direction' => CommunicationDirection::Outbound,
            'subject' => null,
            'body' => fake()->sentence(),
            'sent_at' => null,
            'delivery_status' => DeliveryStatus::Queued,
            'provider_reference' => null,
            'failure_reason' => null,
            'related_type' => null,
            'related_id' => null,
            'actor_id' => null,
        ];
    }
}
