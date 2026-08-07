<?php

declare(strict_types=1);

namespace App\Modules\Identity\Livewire\Users;

use App\Modules\Identity\Actions\CreateUser;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Create a user, docs/specs/09-ui.md section 8.10.
 *
 * A Livewire component can be reached directly (mount()), not only through the
 * route it happens to be registered against, so authorisation is checked in
 * BOTH mount() and save() - the route's `can:user.manage` middleware is not
 * the only door into this class.
 */
#[Layout('layouts.app')]
final class Form extends Component
{
    public string $name = '';

    public string $email = '';

    public string $role = '';

    public string $password = '';

    public function mount(): void
    {
        Gate::authorize(Permission::UserManage->value);
    }

    public function save(CreateUser $createUser): mixed
    {
        Gate::authorize(Permission::UserManage->value);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::enum(Role::class)],
            'password' => ['required', 'string', 'min:12'],
        ]);

        /** @var User $actor */
        $actor = auth()->user();

        $createUser->handle(
            name: $validated['name'],
            email: $validated['email'],
            role: Role::from($validated['role']),
            plainPassword: $validated['password'],
            actor: $actor,
        );

        session()->flash('status', __('opes.users.created'));

        return $this->redirect('/users', navigate: false);
    }

    /**
     * @return list<Role>
     */
    private function roleOptions(): array
    {
        return Role::cases();
    }

    public function render(): mixed
    {
        return view('livewire.users.form', [
            'roleOptions' => $this->roleOptions(),
        ]);
    }
}
