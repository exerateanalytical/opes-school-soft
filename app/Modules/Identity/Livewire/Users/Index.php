<?php

declare(strict_types=1);

namespace App\Modules\Identity\Livewire\Users;

use App\Modules\Identity\Actions\SetUserPassword;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * User Management list, docs/specs/09-ui.md section 8.10.
 *
 * The route already requires `user.view` (routes/web.php), so this component
 * does not repeat that check - it only has to keep the query bounded and the
 * filters linkable.
 *
 * Pagination is hand-rolled (a plain `#[Url] public int $page`) rather than
 * Livewire's WithPagination trait: that trait keeps the current page inside
 * an internal `$paginators` array, not a public `page` property, and this
 * screen's page links are plain <a href> navigations (x-pagination), not
 * wire:click - so nothing needs the trait's Alpine wiring.
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    #[Url]
    public string $search = '';

    #[Url]
    public string $role = '';

    #[Url]
    public string $status = '';

    #[Url]
    public int $page = 1;

    public int $perPage = 25;

    // ── Reset-password toggle panel (per row) ───────────────────────────
    public ?int $passwordFormUserId = null;

    public string $newPassword = '';

    public string $newPasswordConfirmation = '';

    // ── Change-role toggle panel (per row) ──────────────────────────────
    public ?int $roleFormUserId = null;

    public string $selectedRole = '';

    /**
     * Binding rule 3: Reset clears every filter AND returns to page 1. Page 3
     * of a filter that no longer applies renders as a blank screen the
     * operator reads as "no data", not as "your filter is gone".
     */
    public function resetFilters(): void
    {
        $this->reset(['search', 'role', 'status']);
        $this->resetPage();
    }

    private function resetPage(): void
    {
        $this->page = 1;
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedRole(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, User>
     */
    private function users(): LengthAwarePaginator
    {
        return User::query()
            ->when($this->search !== '', function ($query): void {
                $query->where(function ($inner): void {
                    $inner->where('name', 'like', '%'.$this->search.'%')
                        ->orWhere('email', 'like', '%'.$this->search.'%')
                        // The reference's placeholder promises username too,
                        // and the column exists - a search box that silently
                        // ignores a third of what it offers to match on is
                        // worse than one that offers less.
                        ->orWhere('username', 'like', '%'.$this->search.'%');
                });
            })
            ->when($this->role !== '', function ($query): void {
                $query->whereHas('roles', function ($inner): void {
                    $inner->where('name', $this->role);
                });
            })
            ->when($this->status !== '', function ($query): void {
                $query->where('status', $this->status);
            })
            ->orderBy('name')
            ->paginate($this->perPage, ['*'], 'page', $this->page);
    }

    /**
     * The user KPI strip.
     *
     * Counted off the SPATIE role tables rather than off a column on users,
     * because that is where role membership actually lives - a users.role
     * column would be a second source of truth for the same fact.
     *
     * @return array{total: int, active: int, administrators: int, teachers: int, students: int}
     */
    private function userStats(): array
    {
        // selectRaw + an ALIAS, not pluck(DB::raw(...)). pluck's first
        // argument is a COLUMN NAME it reads off each row object, so handing
        // it a raw expression makes it look for a property literally called
        // "COUNT(DISTINCT mhr.model_id)" and fail with an undefined-property
        // error at render time.
        $byRole = DB::table('roles as r')
            ->join('model_has_roles as mhr', 'mhr.role_id', '=', 'r.id')
            ->where('mhr.model_type', User::class)
            ->groupBy('r.name')
            ->selectRaw('r.name as role_name, COUNT(DISTINCT mhr.model_id) as holders')
            ->pluck('holders', 'role_name');

        $administrators = (int) ($byRole['super_admin'] ?? 0) + (int) ($byRole['administrator'] ?? 0) + (int) ($byRole['admin'] ?? 0);

        return [
            'total' => (int) DB::table('users')->count(),
            'active' => (int) DB::table('users')->where('status', 'active')->count(),
            'administrators' => $administrators,
            'teachers' => (int) ($byRole['teacher'] ?? 0),
            // Guardians, not students: a pupil does not get a back-office
            // login in this product, their guardian does. Labelling the tile
            // "Students" over a guardian count would be a plain untruth, so
            // the label says guardians and the figure matches it.
            'guardians' => (int) ($byRole['guardian'] ?? 0),
        ];
    }

    /**
     * Users per role, for the rail donut.
     *
     * @return list<array{label: string, value: int}>
     */
    private function roleDistribution(): array
    {
        return DB::table('roles as r')
            ->join('model_has_roles as mhr', 'mhr.role_id', '=', 'r.id')
            ->where('mhr.model_type', User::class)
            ->groupBy('r.name')
            ->orderByDesc(DB::raw('COUNT(DISTINCT mhr.model_id)'))
            ->selectRaw('r.name as label, COUNT(DISTINCT mhr.model_id) as value')
            ->get()
            ->map(static function (object $row): array {
                $role = Role::tryFrom((string) $row->label);

                return [
                    // The enum's own label, so the donut reads "Teacher"
                    // rather than the raw "teacher" slug the table stores.
                    'label' => $role?->label(app()->getLocale()) ?? (string) $row->label,
                    'value' => (int) $row->value,
                ];
            })
            ->all();
    }

    /**
     * @return list<Role>
     */
    private function roleOptions(): array
    {
        return Role::cases();
    }

    public function togglePasswordForm(int $userId): void
    {
        Gate::authorize(Permission::UserSetPassword->value);

        $this->roleFormUserId = null;

        $this->passwordFormUserId = $this->passwordFormUserId === $userId ? null : $userId;
        $this->reset(['newPassword', 'newPasswordConfirmation']);
        $this->resetErrorBag();
    }

    public function setPassword(int $userId, SetUserPassword $setUserPassword): void
    {
        Gate::authorize(Permission::UserSetPassword->value);

        $validated = $this->validate([
            'newPassword' => ['required', 'string', 'min:12', 'confirmed'],
        ], [], [
            'newPassword' => __('opes.users.password_label'),
        ]);

        $target = User::query()->findOrFail($userId);

        /** @var User $actor */
        $actor = auth()->user();

        try {
            $setUserPassword->handle(
                target: $target,
                plainPassword: $validated['newPassword'],
                actor: $actor,
            );
        } catch (AuthorizationException|DomainException $e) {
            $this->addError('newPassword', $e->getMessage());

            return;
        } catch (ValidationException $e) {
            $this->addError('newPassword', $e->getMessage());

            return;
        }

        $this->reset(['newPassword', 'newPasswordConfirmation', 'passwordFormUserId']);
        session()->flash('status', __('opes.users.password_reset_success'));
    }

    public function toggleRoleForm(int $userId): void
    {
        Gate::authorize(Permission::RoleAssign->value);

        $this->passwordFormUserId = null;

        if ($this->roleFormUserId === $userId) {
            $this->roleFormUserId = null;
            $this->selectedRole = '';
        } else {
            $this->roleFormUserId = $userId;
            $target = User::query()->find($userId);
            $currentRole = $target?->roles->first()?->name;
            $this->selectedRole = $currentRole ?? '';
        }

        $this->resetErrorBag();
    }

    public function changeRole(int $userId, WriteAuditEntry $writeAuditEntry): void
    {
        Gate::authorize(Permission::RoleAssign->value);

        $validated = $this->validate([
            'selectedRole' => ['required', Rule::enum(Role::class)],
        ]);

        $target = User::query()->findOrFail($userId);

        /** @var User $actor */
        $actor = auth()->user();

        $before = $target->roles->pluck('name')->all();
        $newRole = Role::from($validated['selectedRole']);

        try {
            $target->syncRoles([$newRole->value]);
        } catch (DomainException $e) {
            $this->addError('selectedRole', $e->getMessage());

            return;
        }

        $writeAuditEntry->handle(
            action: AuditAction::RoleAssigned,
            module: 'Identity',
            auditableType: User::class,
            auditableId: (int) $target->getKey(),
            before: ['roles' => $before],
            after: ['roles' => [$newRole->value]],
            actor: $actor->toAuditActor(),
        );

        $this->reset(['selectedRole', 'roleFormUserId']);
        session()->flash('status', __('opes.users.role_updated'));
    }

    public function render(): mixed
    {
        return view('livewire.users.index', [
            'users' => $this->users(),
            'roleOptions' => $this->roleOptions(),
            'userStats' => $this->userStats(),
            'roleDistribution' => $this->roleDistribution(),
        ]);
    }
}
