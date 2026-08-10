<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Actions\Webhooks;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Reporting\Models\WebhookEndpoint;
use DomainException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Registers an outbound integration endpoint. Gated on `api.manage_tokens`,
 * the same permission API token issuance uses - both hand a secret that
 * works from outside the building to whoever holds it, which is a heavier
 * right than ordinary settings editing.
 *
 * The generated secret is returned on the model but is otherwise
 * unrecoverable: the caller must show it to the operator exactly once, the
 * same convention as an API token.
 */
final class RegisterWebhookEndpoint
{
    /**
     * @param  list<string>  $events
     */
    public function handle(string $name, string $url, array $events, int $createdBy): WebhookEndpoint
    {
        Gate::authorize(Permission::ApiTokenManage->value);

        if (! filter_var($url, FILTER_VALIDATE_URL) || ! str_starts_with($url, 'https://')) {
            throw new DomainException('The endpoint URL must be a valid https:// address - webhook secrets are never sent over plain http.');
        }

        if ($events === []) {
            throw new DomainException('An endpoint must subscribe to at least one event.');
        }

        return WebhookEndpoint::query()->create([
            'name' => $name,
            'url' => $url,
            'secret' => Str::random(64),
            'events' => $events,
            'is_active' => true,
            'created_by' => $createdBy,
        ]);
    }
}
