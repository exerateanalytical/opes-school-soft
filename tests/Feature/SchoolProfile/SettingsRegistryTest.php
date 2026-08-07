<?php

declare(strict_types=1);

use App\Modules\Identity\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\SchoolProfile\Actions\ReadSetting;
use App\Modules\SchoolProfile\Actions\WriteSetting;
use App\Modules\SchoolProfile\Domain\SettingClass;
use App\Modules\SchoolProfile\Domain\SettingType;
use App\Modules\SchoolProfile\Models\Setting;
use App\Support\Audit\Actor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function settingsActor(): Actor
{
    // Identity owns the User -> Actor conversion; SchoolProfile only ever sees
    // the shared-kernel value object (00-core 6.2).
    return User::factory()->create()->toAuditActor();
}

function defineSetting(string $key, SettingType $type, SettingClass $class, mixed $default, ?string $rule = null): Setting
{
    return Setting::query()->create([
        'key' => $key,
        'value' => json_encode($default, JSON_THROW_ON_ERROR),
        'default_value' => json_encode($default, JSON_THROW_ON_ERROR),
        'value_type' => $type->value,
        'setting_class' => $class->value,
        'scope' => 'global',
        'validation_rule' => $rule,
    ]);
}

it('reads a typed value back as its PHP type, not a string', function () {
    defineSetting('academic.pass_mark', SettingType::Int, SettingClass::EngineBehaviour, 50);
    defineSetting('security.two_factor', SettingType::Bool, SettingClass::Operational, false);

    expect(app(ReadSetting::class)->handle('academic.pass_mark'))->toBe(50);
    expect(app(ReadSetting::class)->handle('security.two_factor'))->toBeFalse();
});

it('returns the fallback for an unknown key rather than throwing', function () {
    expect(app(ReadSetting::class)->handle('does.not.exist', 'fallback'))->toBe('fallback');
});

it('writes and audits a cosmetic setting', function () {
    defineSetting('ui.theme', SettingType::String, SettingClass::Cosmetic, 'green');

    app(WriteSetting::class)->handle('ui.theme', 'blue', settingsActor());

    expect(app(ReadSetting::class)->handle('ui.theme'))->toBe('blue');
    expect(AuditLog::query()->where('action', 'setting_changed')->count())->toBe(1);
});

it('records the old and new value in the audit entry', function () {
    defineSetting('ui.theme', SettingType::String, SettingClass::Cosmetic, 'green');

    app(WriteSetting::class)->handle('ui.theme', 'blue', settingsActor());

    $entry = AuditLog::query()->where('action', 'setting_changed')->firstOrFail();

    expect($entry->before)->toBe(['ui.theme' => 'green']);
    expect($entry->after)->toBe(['ui.theme' => 'blue']);
});

it('enforces the validation rule', function () {
    defineSetting('academic.pass_mark', SettingType::Int, SettingClass::EngineBehaviour, 50, 'integer|min:0|max:100');

    app(WriteSetting::class)->handle('academic.pass_mark', 150, settingsActor());
})->throws(\Illuminate\Validation\ValidationException::class);

it('rejects a value of the wrong type', function () {
    defineSetting('academic.pass_mark', SettingType::Int, SettingClass::EngineBehaviour, 50);

    app(WriteSetting::class)->handle('academic.pass_mark', 'fifty', settingsActor());
})->throws(\Illuminate\Validation\ValidationException::class);

it('refuses to change a locked engine-behaviour setting', function () {
    // The lock is what stops changing pass_mark from 50 to 45 in March from
    // silently reclassifying every mark already published (09-ui 7.3).
    $setting = defineSetting('academic.pass_mark', SettingType::Int, SettingClass::EngineBehaviour, 50);
    $setting->update(['locked_at' => now(), 'locked_reason' => 'Term 1 published']);

    app(WriteSetting::class)->handle('academic.pass_mark', 45, settingsActor());
})->throws(RuntimeException::class, 'locked');

it('still allows cosmetic changes while engine settings are locked', function () {
    defineSetting('ui.theme', SettingType::String, SettingClass::Cosmetic, 'green');
    $engine = defineSetting('academic.pass_mark', SettingType::Int, SettingClass::EngineBehaviour, 50);
    $engine->update(['locked_at' => now(), 'locked_reason' => 'Term 1 published']);

    app(WriteSetting::class)->handle('ui.theme', 'blue', settingsActor());

    expect(app(ReadSetting::class)->handle('ui.theme'))->toBe('blue');
});

it('caches reads and invalidates on write', function () {
    defineSetting('ui.theme', SettingType::String, SettingClass::Cosmetic, 'green');

    expect(app(ReadSetting::class)->handle('ui.theme'))->toBe('green');

    app(WriteSetting::class)->handle('ui.theme', 'blue', settingsActor());

    expect(app(ReadSetting::class)->handle('ui.theme'))->toBe('blue');
});

it('cannot hold two rows for the same key and scope', function () {
    defineSetting('ui.theme', SettingType::String, SettingClass::Cosmetic, 'green');

    defineSetting('ui.theme', SettingType::String, SettingClass::Cosmetic, 'blue');
})->throws(\Illuminate\Database\QueryException::class);

it('treats keys as case sensitive', function () {
    defineSetting('ui.theme', SettingType::String, SettingClass::Cosmetic, 'green');

    // as_cs collation: UI.Theme and ui.theme are different keys, so this must
    // NOT collide with the row above.
    defineSetting('UI.Theme', SettingType::String, SettingClass::Cosmetic, 'blue');

    expect(Setting::query()->count())->toBe(2);
});
