<?php

declare(strict_types=1);

namespace App\Modules\SchoolProfile\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\SchoolProfile\Models\Setting;
use App\Support\Audit\Actor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

final class WriteSetting
{
    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    public function handle(
        string $key,
        mixed $value,
        Actor $actor,
        string $scope = 'global',
        ?int $scopeId = null,
    ): Setting {
        return DB::transaction(function () use ($key, $value, $actor, $scope, $scopeId): Setting {
            $setting = Setting::query()
                ->where('key', $key)
                ->where('scope', $scope)
                ->when($scopeId === null, static fn ($q) => $q->whereNull('scope_id'))
                ->when($scopeId !== null, static fn ($q) => $q->where('scope_id', $scopeId))
                ->lockForUpdate()
                ->firstOrFail();

            if ($setting->isLocked()) {
                throw new RuntimeException(
                    "Setting [{$key}] is locked: {$setting->locked_reason}. "
                    .'Engine-behaviour settings cannot change once a period using them is published.'
                );
            }

            $rules = $setting->type()->baseRule();

            if ($setting->validation_rule !== null && $setting->validation_rule !== '') {
                $rules = $setting->validation_rule;
            }

            Validator::validate(['value' => $value], ['value' => $rules]);

            $previous = $setting->typedValue();

            $setting->value = json_encode($value, JSON_THROW_ON_ERROR);
            $setting->updated_by = $actor->id;
            $setting->save();

            Cache::forget(ReadSetting::cacheKey($key, $scope, $scopeId));

            $this->audit->handle(
                action: AuditAction::SettingChanged,
                module: 'SchoolProfile',
                auditableType: Setting::class,
                auditableId: (int) $setting->getKey(),
                before: [$key => $previous],
                after: [$key => $value],
                actor: $actor,
            );

            return $setting;
        });
    }
}
