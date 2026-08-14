<?php

declare(strict_types=1);

namespace App\Modules\Identity\Livewire;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * /account - the staff shell's own-account screen.
 *
 * There was none. A teacher, accountant or registrar could not see their own
 * record or change their own password; only an Administrator could, from
 * /users. The guardian portal shipped five account screens and the staff
 * shell shipped zero, which is the sharpest self-service gap in the product.
 *
 * NO permission gate: this is the authenticated user acting on themselves.
 * It is deliberately NOT Identity\Actions\SetUserPassword, which is the
 * ADMIN-resets-SOMEONE-ELSE's-password door and rightly gates on
 * `user.set_password`. Same reasoning, same shape, as the staff portal's
 * changePassword (HR\Livewire\Portal\Show).
 */
#[Layout('layouts.app')]
final class Account extends Component
{
    public string $name = '';

    public string $email = '';

    public string $currentPassword = '';

    public string $newPassword = '';

    public string $newPasswordConfirmation = '';

    public function mount(): void
    {
        $user = auth()->user();

        if ($user === null) {
            abort(403);
        }

        $this->name = (string) $user->name;
        $this->email = (string) $user->email;
    }

    public function changePassword(): void
    {
        $userId = auth()->id();

        if ($userId === null) {
            abort(403);
        }

        $hash = DB::table('users')->where('id', $userId)->value('password');

        if (! is_string($hash) || ! Hash::check($this->currentPassword, $hash)) {
            throw ValidationException::withMessages([
                'currentPassword' => __('opes.account.password_current_wrong'),
            ]);
        }

        if ($this->newPassword === '' || $this->newPassword !== $this->newPasswordConfirmation) {
            throw ValidationException::withMessages([
                'newPasswordConfirmation' => __('opes.account.password_mismatch'),
            ]);
        }

        if (strlen($this->newPassword) < 8) {
            throw ValidationException::withMessages([
                'newPassword' => __('opes.account.password_too_short'),
            ]);
        }

        DB::table('users')->where('id', $userId)->update([
            'password' => Hash::make($this->newPassword),
            // A user who has just chosen their own password is no longer
            // under a forced change.
            'must_change_password_at' => null,
            'updated_at' => now(),
        ]);

        $this->reset(['currentPassword', 'newPassword', 'newPasswordConfirmation']);

        session()->flash('status', __('opes.account.password_changed'));
    }

    /**
     * @return list<string>
     */
    public function roleLabels(): array
    {
        $user = auth()->user();

        if ($user === null) {
            return [];
        }

        /** @var list<string> $names */
        $names = $user->getRoleNames()->all();

        return $names;
    }

    public function render(): mixed
    {
        return view('livewire.identity.account', [
            'roles' => $this->roleLabels(),
        ]);
    }
}
