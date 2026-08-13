<?php

declare(strict_types=1);

namespace App\Modules\HR\Actions;

use App\Modules\HR\Domain\HrPermission;
use App\Modules\Identity\Actions\CreateUser;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * `/staff` row action - grants a staff member their own self-service portal
 * login (payslips, leave, timetable at `HR\Livewire\Portal\Show`, gated on
 * the `staff_portal` role). That screen and its context resolver
 * (`StaffPortalContext`, keyed on `staff_members.portal_user_id`) have
 * existed since Phase 12-13; nothing anywhere ever created the user or set
 * that column, so no staff member - demo or real - could ever reach the
 * portal built for them.
 *
 * Admin-mediated rather than self-activated (unlike Guardians'
 * IssuePortalInvitation/ActivatePortalAccount pair): this platform sends no
 * email (the login page says so explicitly), so there is no channel for an
 * invitation code to reach the staff member except the admin handing over
 * credentials directly - the same reason `Identity\Actions\CreateUser`
 * already sets a plain temporary password rather than emailing one.
 */
final class GrantStaffPortalAccess
{
    public function __construct(private readonly CreateUser $createUser) {}

    /**
     * @return array{user: User, temporary_password: string}
     */
    public function handle(int $staffMemberId, ?string $email, User $actor): array
    {
        Gate::authorize(HrPermission::MANAGE);

        /** @var object{id: int, first_name: string, last_name: string, email: string|null, portal_user_id: int|null}|null $staff */
        $staff = DB::table('staff_members')->where('id', $staffMemberId)
            ->first(['id', 'first_name', 'last_name', 'email', 'portal_user_id']);

        if ($staff === null) {
            throw new DomainException("Staff member {$staffMemberId} does not exist.");
        }

        if ($staff->portal_user_id !== null) {
            throw new DomainException('This staff member already has portal access.');
        }

        $resolvedEmail = ($email !== null && $email !== '') ? $email : $staff->email;

        if ($resolvedEmail === null) {
            throw ValidationException::withMessages([
                'email' => 'This staff member has no email on file; supply one to grant portal access.',
            ]);
        }

        if (User::query()->where('email', $resolvedEmail)->exists()) {
            throw ValidationException::withMessages([
                'email' => "A user with email {$resolvedEmail} already exists.",
            ]);
        }

        $password = Str::random(16).'Aa1!';

        return DB::transaction(function () use ($staff, $resolvedEmail, $password, $actor): array {
            $user = $this->createUser->handle(
                trim($staff->first_name.' '.$staff->last_name),
                $resolvedEmail,
                Role::StaffPortal,
                $password,
                $actor,
            );

            $user->forceFill(['must_change_password_at' => now()])->save();

            DB::table('staff_members')->where('id', $staff->id)->update([
                'portal_user_id' => $user->id,
                'updated_at' => now(),
            ]);

            return ['user' => $user, 'temporary_password' => $password];
        });
    }
}
