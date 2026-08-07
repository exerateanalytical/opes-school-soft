<?php

declare(strict_types=1);

namespace App\Modules\Operations\Http;

use App\Modules\Operations\Actions\CollectHealth;
use App\Modules\Operations\Domain\HealthCheckResult;
use App\Modules\Operations\Domain\HealthStatus;
use Illuminate\Http\JsonResponse;

/**
 * The machine-readable half of the health page, served at /up.
 *
 * UNAUTHENTICATED by design, so a monitor can poll it without holding a
 * credential - which makes it the one endpoint where a leak costs the most.
 * Every string that leaves here is passed through redact() first: an
 * absolute path tells an attacker the install layout, and the message of an
 * unexpected exception is the most likely place for one to appear.
 */
final class HealthController
{
    public function __invoke(CollectHealth $health): JsonResponse
    {
        $results = $health->handle();

        return response()->json([
            'status' => HealthStatus::worst(
                ...array_map(static fn (HealthCheckResult $r): HealthStatus => $r->status, $results)
            )->value,
            'version' => (string) config('app.version', 'dev'),
            'checks' => array_map(fn (HealthCheckResult $r): array => $this->present($r), $results),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function present(HealthCheckResult $result): array
    {
        return array_map(fn (string $value): string => $this->redact($value), $result->toArray());
    }

    /**
     * Known roots become plain-language names; anything else that still looks
     * like an absolute path is blanked outright. The order matters - the
     * specific replacements run before the catch-all, so the useful wording
     * survives.
     */
    private function redact(string $value): string
    {
        $backupPath = config('opes.backup.path');

        /** @var array<string, string> $replacements */
        $replacements = [];

        if (is_string($backupPath) && $backupPath !== '') {
            $replacements[$backupPath] = 'the backup folder';
        }

        $replacements[storage_path()] = 'the storage folder';
        $replacements[base_path()] = 'the installation folder';

        foreach ($replacements as $needle => $label) {
            $value = str_replace($needle, $label, $value);
        }

        // Windows drive-letter paths and Unix absolute paths alike.
        $value = (string) preg_replace('#\b[A-Za-z]:[\\\\/][^\s"\']*#', '[path]', $value);

        return (string) preg_replace('#(?<![\w/])/(?:home|var|usr|etc|opt|srv|root)/[^\s"\']*#', '[path]', $value);
    }
}
