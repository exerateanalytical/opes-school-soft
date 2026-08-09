<?php

declare(strict_types=1);

namespace App\Modules\Identity\Livewire\Users;

use App\Modules\Identity\Actions\IssueApiToken;
use App\Modules\Identity\Actions\RevokeApiToken;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * API token management for one user, routed at /users/{user}/tokens
 * (docs/plans/phase-12-13.md 12.4).
 *
 * Abilities offered are exactly the Permission enum values - the token
 * vocabulary and the permission vocabulary are one vocabulary, so the
 * `can:` and `abilities:` gates in routes/api.php can mirror each other
 * without a mapping table.
 *
 * The plaintext token is displayed ONCE, from the component property, and
 * never persisted anywhere but as Sanctum's SHA-256 hash. Reloading the
 * screen loses it by design.
 *
 * Same double-check pattern as Users\Form: the route carries
 * `can:api.manage_tokens`, and mount()/every mutation re-authorize, because
 * a Livewire component can be reached without its route.
 */
#[Layout('layouts.app')]
final class Tokens extends Component
{
    public User $user;

    public string $name = '';

    /** @var list<string> */
    public array $abilities = [];

    public ?string $plainTextToken = null;

    public function mount(User $user): void
    {
        Gate::authorize(Permission::ApiTokenManage->value);

        $this->user = $user;
    }

    public function createToken(IssueApiToken $issue): void
    {
        Gate::authorize(Permission::ApiTokenManage->value);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['string'],
        ]);

        /** @var User $actor */
        $actor = auth()->user();

        /** @var list<string> $abilities */
        $abilities = array_values($validated['abilities']);

        $token = $issue->handle(
            tokenOwner: $this->user,
            name: $validated['name'],
            abilities: $abilities,
            actor: $actor,
        );

        $this->plainTextToken = $token->plainTextToken;
        $this->reset(['name', 'abilities']);
    }

    public function revoke(int $tokenId, RevokeApiToken $revoke): void
    {
        Gate::authorize(Permission::ApiTokenManage->value);

        /** @var User $actor */
        $actor = auth()->user();

        $revoke->handle(tokenOwner: $this->user, tokenId: $tokenId, actor: $actor);
    }

    public function render(): mixed
    {
        return view('livewire.users.tokens', [
            'tokens' => $this->user->tokens()->orderByDesc('created_at')->get(),
            'abilityOptions' => Permission::cases(),
        ]);
    }
}
