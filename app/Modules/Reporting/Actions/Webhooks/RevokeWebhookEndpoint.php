<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Actions\Webhooks;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Reporting\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Gate;

/**
 * Deactivates an endpoint. Never deletes: its delivery history stays for
 * audit, and delivery rows carry a RESTRICT foreign key to the endpoint
 * for exactly that reason.
 */
final class RevokeWebhookEndpoint
{
    public function handle(int $endpointId): WebhookEndpoint
    {
        Gate::authorize(Permission::ApiTokenManage->value);

        /** @var WebhookEndpoint $endpoint */
        $endpoint = WebhookEndpoint::query()->findOrFail($endpointId);
        $endpoint->forceFill(['is_active' => false])->save();

        return $endpoint->refresh();
    }
}
