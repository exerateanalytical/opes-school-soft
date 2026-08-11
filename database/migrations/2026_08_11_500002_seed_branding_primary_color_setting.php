<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every school running this platform gets the Heritage green/gold shell by
 * default (resources/css/app.css @theme), but the platform is deployed
 * per-school, not multi-tenant SaaS - a different school has no reason to
 * run under HERITAGE's brand colour. This seeds the one setting a Branding
 * screen needs to let a school pick its own: `branding.primary_color`.
 *
 * Seeded through the generic Setting store (WriteSetting::handle() requires
 * the row to already exist, per its own firstOrFail() contract - settings
 * are never created ad hoc, only ever seeded then edited) rather than a
 * dedicated column, because this is exactly what that store exists for: a
 * single cosmetic, audited, typed value with no engine behaviour riding on
 * it. The seeded default is the current --color-primary value, so applying
 * it changes nothing until a school actually picks a different colour.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => 'branding.primary_color', 'scope' => 'global'],
            [
                'value' => json_encode('#0B5A32', JSON_THROW_ON_ERROR),
                'default_value' => json_encode('#0B5A32', JSON_THROW_ON_ERROR),
                'value_type' => 'string',
                'setting_class' => 'cosmetic',
                'scope' => 'global',
                // Overrides SettingType::String's baseRule() entirely
                // (WriteSetting::handle()) - a 6-digit hex colour, nothing
                // else, so a bad picker value can never reach the shell's
                // inline <style> block unescaped.
                'validation_rule' => 'regex:/^#[0-9A-Fa-f]{6}$/',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'branding.primary_color')->where('scope', 'global')->delete();
    }
};
