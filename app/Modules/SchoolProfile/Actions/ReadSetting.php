<?php

declare(strict_types=1);

namespace App\Modules\SchoolProfile\Actions;

use App\Modules\SchoolProfile\Models\Setting;
use Illuminate\Support\Facades\Cache;

final class ReadSetting
{
    public const CACHE_PREFIX = 'opes.setting.';

    public function handle(string $key, mixed $fallback = null, string $scope = 'global', ?int $scopeId = null): mixed
    {
        $cacheKey = self::cacheKey($key, $scope, $scopeId);

        /** @var array{hit: bool, value: mixed} $cached */
        $cached = Cache::rememberForever($cacheKey, static function () use ($key, $scope, $scopeId): array {
            $setting = Setting::query()
                ->where('key', $key)
                ->where('scope', $scope)
                ->when($scopeId === null, static fn ($q) => $q->whereNull('scope_id'))
                ->when($scopeId !== null, static fn ($q) => $q->where('scope_id', $scopeId))
                ->first();

            return $setting === null
                ? ['hit' => false, 'value' => null]
                : ['hit' => true, 'value' => $setting->typedValue()];
        });

        return $cached['hit'] ? $cached['value'] : $fallback;
    }

    public static function cacheKey(string $key, string $scope, ?int $scopeId): string
    {
        return self::CACHE_PREFIX.$scope.'.'.($scopeId ?? 0).'.'.$key;
    }
}
