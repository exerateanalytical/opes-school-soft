<?php

declare(strict_types=1);

namespace App\Modules\Operations\Domain;

enum HealthStatus: string
{
    case Ok = 'ok';
    case Amber = 'amber';
    case Red = 'red';

    public function severity(): int
    {
        return match ($this) {
            self::Ok => 0,
            self::Amber => 1,
            self::Red => 2,
        };
    }

    public static function worst(HealthStatus ...$statuses): self
    {
        $worst = self::Ok;

        foreach ($statuses as $status) {
            if ($status->severity() > $worst->severity()) {
                $worst = $status;
            }
        }

        return $worst;
    }
}
