<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Guardians\Domain\PortalSubjectType;
use App\Modules\Guardians\Models\Guardian;
use App\Modules\Guardians\Models\PortalInvitation;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @extends Factory<PortalInvitation>
 */
class PortalInvitationFactory extends Factory
{
    /** @var class-string<PortalInvitation> */
    protected $model = PortalInvitation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subject_type' => PortalSubjectType::Guardian,
            'subject_id' => Guardian::factory(),
            // A random hash, NOT the hash of a known code: a factory row is
            // unredeemable by construction, and tests that need a redeemable
            // invitation go through IssuePortalInvitation like production.
            'code_hash' => hash('sha256', Str::random(40)),
            'expires_at' => Carbon::now()->addDays(14),
            'used_at' => null,
            'used_by_user_id' => null,
            'revoked_at' => null,
            'issued_by' => User::factory(),
            'issued_at' => Carbon::now(),
        ];
    }
}
