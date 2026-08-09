<?php

declare(strict_types=1);

namespace App\Modules\HR\Livewire\Portal;

use App\Modules\HR\Support\StaffPortalContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * `/portal/staff` - the staff portal shell (docs/plans/phase-12-13.md 12.3).
 *
 * Profile and password change work now; timetable, leave and payslips are
 * rendered as "scheduled" panels rather than wired to Phase 8/11 data - this
 * build's brief is the SHELL, matching the plan's own phrasing ("staff
 * portal shell"). The underlying modules exist in this repository, but
 * wiring per-staff self-service reads of them (own timetable, own leave
 * balance, own payslip PDF) is real per-module scope of its own and is
 * listed in remaining_issues rather than rushed here.
 */
#[Layout('layouts.portal')]
final class Show extends Component
{
    public string $currentPassword = '';

    public string $newPassword = '';

    public string $newPasswordConfirmation = '';

    /**
     * Self-service only: the authenticated user changing their OWN
     * password needs no staff permission - it is not `SetUserPassword`
     * (Identity\Actions), which is the ADMIN-resets-SOMEONE-ELSE's-password
     * door and rightly gates on `user.set_password`. Written via the query
     * builder (not the `User` model) for the same cross-module reason
     * StaffPortalContext gives.
     */
    public function changePassword(): void
    {
        $userId = auth()->id();

        if ($userId === null) {
            abort(403);
        }

        $hash = DB::table('users')->where('id', $userId)->value('password');

        if (! is_string($hash) || ! Hash::check($this->currentPassword, $hash)) {
            throw ValidationException::withMessages([
                'currentPassword' => __('opes.staff_portal.password_current_wrong'),
            ]);
        }

        if ($this->newPassword === '' || $this->newPassword !== $this->newPasswordConfirmation) {
            throw ValidationException::withMessages([
                'newPasswordConfirmation' => __('opes.staff_portal.password_mismatch'),
            ]);
        }

        if (strlen($this->newPassword) < 8) {
            throw ValidationException::withMessages([
                'newPassword' => __('opes.staff_portal.password_too_short'),
            ]);
        }

        DB::table('users')->where('id', $userId)->update([
            'password' => Hash::make($this->newPassword),
            'must_change_password_at' => null,
            'updated_at' => now(),
        ]);

        $this->reset(['currentPassword', 'newPassword', 'newPasswordConfirmation']);
        $this->dispatch('opes-portal-password-changed');
    }

    public function render(): mixed
    {
        $context = StaffPortalContext::current();

        if ($context === null) {
            abort(403);
        }

        $staff = DB::table('staff_members')->where('id', $context->staffMemberId)->first([
            'staff_no', 'first_name', 'last_name', 'phone', 'email', 'status',
        ]);

        $contract = DB::table('staff_contracts')
            ->where('staff_member_id', $context->staffMemberId)
            ->whereNull('ends_on')
            ->orderByDesc('starts_on')
            ->first(['contract_role', 'starts_on']);

        return view('livewire.hr.portal.show', [
            'staff' => $staff,
            'contract' => $contract,
        ]);
    }
}
