<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Livewire\Webhooks;

use App\Modules\Reporting\Actions\Webhooks\RegisterWebhookEndpoint;
use App\Modules\Reporting\Actions\Webhooks\RevokeWebhookEndpoint;
use App\Modules\Reporting\Models\WebhookDelivery;
use App\Modules\Reporting\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Register/revoke outbound webhook endpoints and inspect the delivery log.
 * The generated secret is shown exactly once, in `revealedSecret`, and
 * never re-displayed after this component re-renders past that point.
 */
final class Index extends Component
{
    public bool $showForm = false;

    public string $name = '';

    public string $url = '';

    public string $eventsInput = '';

    public string $revealedSecret = '';

    public string $error = '';

    public function register(): void
    {
        $this->error = '';
        $this->revealedSecret = '';

        $events = array_values(array_filter(array_map('trim', explode(',', $this->eventsInput))));

        try {
            $endpoint = app(RegisterWebhookEndpoint::class)->handle(
                $this->name, $this->url, $events, (int) Auth::id(),
            );

            $this->revealedSecret = $endpoint->secret;
            $this->showForm = false;
            $this->name = '';
            $this->url = '';
            $this->eventsInput = '';
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function revoke(int $endpointId): void
    {
        $this->error = '';

        try {
            app(RevokeWebhookEndpoint::class)->handle($endpointId);
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function render(): View
    {
        return view('livewire.reporting.webhooks.index', [
            'endpoints' => WebhookEndpoint::query()->orderByDesc('id')->get(),
            'deliveries' => WebhookDelivery::query()
                ->join('webhook_endpoints as e', 'e.id', '=', 'webhook_deliveries.webhook_endpoint_id')
                ->orderByDesc('webhook_deliveries.id')
                ->limit(50)
                ->get(['webhook_deliveries.*', 'e.name as endpoint_name']),
        ]);
    }
}
