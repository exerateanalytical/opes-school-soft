<?php

declare(strict_types=1);

namespace App\Modules\Communication\Support;

use RuntimeException;

/**
 * Resolves the configured outbox driver.
 *
 * The key is `opes.communication.driver` and the default is `log`, so an
 * instance with no configuration at all still drains its outbox instead of
 * silently piling up rows nobody looks at. Drivers register themselves in
 * the map below; a real gateway (when 08-operations 11.1's commercial
 * decision is made) is one more entry here and nothing else.
 */
final class DriverManager
{
    /** @var array<string, MessageDriver>|null */
    private ?array $drivers = null;

    /** @return array<string, MessageDriver> */
    private function drivers(): array
    {
        if ($this->drivers === null) {
            $log = new LogDriver;
            $null = new NullDriver;

            // The WhatsApp driver is the "one more entry here and nothing
            // else" this class anticipated. Resolved from the container
            // rather than `new`-ed because it has dependencies (the config
            // reader and the sending Action); built lazily with the rest, so
            // an instance running the `log` driver never constructs it.
            $whatsapp = app(WhatsAppDriver::class);

            $this->drivers = [
                $log->name() => $log,
                $null->name() => $null,
                $whatsapp->name() => $whatsapp,
            ];
        }

        return $this->drivers;
    }

    /** The driver named in config, or `log`. */
    public function resolve(?string $name = null): MessageDriver
    {
        $key = $name ?? (string) config('opes.communication.driver', 'log');
        $key = $key === '' ? 'log' : $key;

        $drivers = $this->drivers();

        if (! array_key_exists($key, $drivers)) {
            throw new RuntimeException(
                "Unknown communication driver [{$key}]. Known drivers: ".implode(', ', array_keys($drivers)).'.'
            );
        }

        return $drivers[$key];
    }

    /** @return list<string> */
    public function available(): array
    {
        return array_keys($this->drivers());
    }
}
