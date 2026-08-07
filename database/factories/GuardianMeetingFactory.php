<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Guardians\Domain\FollowUpStatus;
use App\Modules\Guardians\Domain\MeetingRequestedBy;
use App\Modules\Guardians\Domain\MeetingStatus;
use App\Modules\Guardians\Domain\MeetingType;
use App\Modules\Guardians\Models\Guardian;
use App\Modules\Guardians\Models\GuardianMeeting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GuardianMeeting>
 *
 * `student_id` defaults to null - a meeting may legitimately concern the
 * guardian alone - and, as elsewhere in this module, is never invented by a
 * factory that has no right to write another module's table.
 */
class GuardianMeetingFactory extends Factory
{
    /** @var class-string<GuardianMeeting> */
    protected $model = GuardianMeeting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'guardian_id' => Guardian::factory(),
            'student_id' => null,
            'scheduled_at' => fake()->dateTimeBetween('-1 month', '+1 month'),
            'held_at' => null,
            'location' => 'Principal office',
            'meeting_type' => MeetingType::ParentTeacher,
            'requested_by' => MeetingRequestedBy::School,
            'agenda' => fake()->sentence(),
            'attendees' => null,
            'minutes' => null,
            'decisions' => null,
            'follow_up_action' => null,
            'follow_up_due_on' => null,
            'follow_up_status' => FollowUpStatus::None,
            'status' => MeetingStatus::Scheduled,
            'created_by' => null,
        ];
    }
}
