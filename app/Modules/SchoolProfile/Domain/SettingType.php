<?php

declare(strict_types=1);

namespace App\Modules\SchoolProfile\Domain;

enum SettingType: string
{
    case String = 'string';
    case Int = 'int';
    case Bool = 'bool';
    case Json = 'json';

    /** Laravel validation rule fragment enforcing the storage type. */
    public function baseRule(): string
    {
        return match ($this) {
            self::String => 'string',
            self::Int => 'integer',
            self::Bool => 'boolean',
            self::Json => 'array',
        };
    }

    public function cast(mixed $decoded): mixed
    {
        return match ($this) {
            self::String => (string) $decoded,
            self::Int => (int) $decoded,
            self::Bool => (bool) $decoded,
            self::Json => $decoded,
        };
    }
}
