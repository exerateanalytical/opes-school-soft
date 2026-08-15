<?php

declare(strict_types=1);

use App\Support\Branding\BrandTokens;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The branding screen grew from one colour to a palette, a school logo and a
 * favicon. WriteSetting::handle() does firstOrFail() - a settings key that
 * was never seeded can never be written - so every new key has to arrive
 * through a migration like this one.
 *
 * `branding.palette` is JSON and validated by BrandTokens rather than by a
 * `validation_rule` regex: the rule that matters is cross-field (contrast
 * between the picked colours), which no single-value rule can express. The
 * `array` base rule below is the outer shape only; BrandTokens::fromArray()
 * is what refuses a bad hex, and the Branding screen calls it BEFORE
 * WriteSetting ever sees the value.
 *
 * `branding.primary_color` (seeded by 2026_08_11_500002) is deliberately
 * left in place: the shell layout and anything else already reading it keeps
 * working, and the Branding screen writes it as a mirror of the palette's
 * primary in the same save.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('settings')->updateOrInsert(
            ['key' => 'branding.palette', 'scope' => 'global'],
            [
                'value' => json_encode(BrandTokens::DEFAULTS, JSON_THROW_ON_ERROR),
                'default_value' => json_encode(BrandTokens::DEFAULTS, JSON_THROW_ON_ERROR),
                'value_type' => 'json',
                'setting_class' => 'cosmetic',
                'scope' => 'global',
                'validation_rule' => 'array',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        foreach (['branding.app_logo_path', 'branding.favicon_path'] as $key) {
            DB::table('settings')->updateOrInsert(
                ['key' => $key, 'scope' => 'global'],
                [
                    'value' => json_encode('', JSON_THROW_ON_ERROR),
                    'default_value' => json_encode('', JSON_THROW_ON_ERROR),
                    'value_type' => 'string',
                    'setting_class' => 'cosmetic',
                    'scope' => 'global',
                    // A relative path on the `public` disk, or empty. Never a
                    // URL and never absolute: the uploader writes
                    // content-hashed relative paths and nothing else may be
                    // hand-typed into a <link rel="icon"> or an <img src>.
                    'validation_rule' => 'nullable|string|max:255|regex:/^(branding\/[A-Za-z0-9._-]+)?$/',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }

    public function down(): void
    {
        DB::table('settings')
            ->whereIn('key', ['branding.palette', 'branding.app_logo_path', 'branding.favicon_path'])
            ->where('scope', 'global')
            ->delete();
    }
};
