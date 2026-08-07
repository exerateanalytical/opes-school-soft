<?php

declare(strict_types=1);

namespace App\Modules\Operations\Console;

use App\Modules\Operations\Actions\CollectHealth;
use App\Modules\Operations\Domain\HealthCheckResult;
use App\Modules\Operations\Domain\HealthStatus;
use Illuminate\Console\Command;
use Illuminate\Foundation\Application;

/**
 * Written for whoever is standing at the server, which in a Cameroonian school
 * is the bursar and not an engineer. Every line that is not green is followed
 * by the thing to do about it (08-operations 7).
 */
final class HealthCommand extends Command
{
    protected $signature = 'opes:health';

    protected $description = 'Show the health of this installation, with what to do about anything wrong.';

    public function handle(CollectHealth $health): int
    {
        $results = $health->handle();

        $this->line('');

        foreach ($results as $result) {
            $this->render($result);
        }

        $this->line('');

        $worst = HealthStatus::worst(
            ...array_map(static fn (HealthCheckResult $r): HealthStatus => $r->status, $results)
        );

        // Printed here rather than in AppVersionCheck, because that result is
        // also published on the unauthenticated /up endpoint and the exact
        // patch versions are a shopping list of applicable CVEs. Reaching this
        // command needs server access already.
        $this->line(sprintf('  Laravel %s on PHP %s', Application::VERSION, PHP_VERSION));
        $this->line('');

        return match ($worst) {
            HealthStatus::Red => $this->conclude(
                'error',
                'Something needs attention today. Follow the arrows above.',
            ),
            HealthStatus::Amber => $this->conclude(
                'warn',
                'Working, but with warnings. Follow the arrows above this week.',
            ),
            HealthStatus::Ok => $this->conclude('info', 'All checks passed.'),
        };
    }

    private function conclude(string $style, string $message): int
    {
        match ($style) {
            'error' => $this->error($message),
            'warn' => $this->warn($message),
            default => $this->info($message),
        };

        return $style === 'error' ? self::FAILURE : self::SUCCESS;
    }

    private function render(HealthCheckResult $result): void
    {
        $label = str_pad($result->label, 24);

        $line = match ($result->status) {
            HealthStatus::Ok => "  <fg=green>PASS</>  {$label} {$result->detail}",
            HealthStatus::Amber => "  <fg=yellow>WARN</>  {$label} {$result->detail}",
            HealthStatus::Red => "  <fg=red>FAIL</>  {$label} {$result->detail}",
        };

        $this->line($line);

        if ($result->status !== HealthStatus::Ok && $result->remedy !== '') {
            foreach ($this->wrap($result->remedy) as $index => $chunk) {
                $this->line($index === 0
                    ? "        <fg=cyan>-></> {$chunk}"
                    : "           {$chunk}");
            }
        }
    }

    /**
     * @return list<string>
     */
    private function wrap(string $text): array
    {
        return explode("\n", wordwrap($text, 86, "\n", false));
    }
}
