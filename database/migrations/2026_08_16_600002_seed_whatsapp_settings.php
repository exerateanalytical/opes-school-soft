<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the settings rows behind /settings/whatsapp.
 *
 * WriteSetting::handle() calls firstOrFail() by contract - settings are never
 * created ad hoc, only seeded then edited - so the admin screen cannot save
 * anything until these rows exist.
 *
 * EVERY VALUE IS SEEDED EMPTY, and `whatsapp.enabled` false. This is the
 * whole point: no credential in this repository is real, none is guessed, and
 * a freshly migrated instance has a WhatsApp channel that is present,
 * visible, and inert. It starts working the moment a principal pastes their
 * own token from developers.facebook.com, and not one moment sooner.
 *
 * `setting_class` is 'operational' rather than 'engine': changing a token
 * cannot invalidate a published assessment period, so these must never be
 * lockable the way engine settings are - a school locked out of rotating an
 * expired token would have no way back.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (self::rows() as $key => [$type, $rule]) {
            // The .env value is the INSTALL default and is copied in here;
            // from this point the admin screen owns it (see
            // WhatsAppConfig::enabled). Everything else seeds EMPTY - a
            // credential is never read out of the environment into the
            // database behind the operator's back.
            $default = $type === 'bool' ? (bool) env('WHATSAPP_ENABLED', false) : '';

            DB::table('settings')->updateOrInsert(
                ['key' => $key, 'scope' => 'global'],
                [
                    'value' => json_encode($default, JSON_THROW_ON_ERROR),
                    'default_value' => json_encode($default, JSON_THROW_ON_ERROR),
                    'value_type' => $type,
                    'setting_class' => 'operational',
                    'scope' => 'global',
                    'validation_rule' => $rule,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    public function down(): void
    {
        DB::table('settings')
            ->whereIn('key', array_keys(self::rows()))
            ->where('scope', 'global')
            ->delete();
    }

    /**
     * key => [value_type, validation_rule]
     *
     * The rules allow an EMPTY string throughout, because clearing a
     * credential is a legitimate operator action - it is how a school
     * decommissions the channel or revokes a leaked token in a hurry.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private static function rows(): array
    {
        return [
            'whatsapp.enabled' => ['bool', 'boolean'],
            // Meta's System User tokens are long (200+ chars) and opaque.
            'whatsapp.access_token' => ['string', 'present|string|max:500'],
            // Meta phone number ids are numeric strings, ~15 digits.
            'whatsapp.phone_number_id' => ['string', 'present|string|max:32|regex:/^[0-9]*$/'],
            'whatsapp.business_account_id' => ['string', 'present|string|max:32|regex:/^[0-9]*$/'],
            // Template names: lowercase letters, digits, underscores (Meta's
            // own constraint on an approved template name).
            'whatsapp.default_template' => ['string', 'present|string|max:120|regex:/^[a-z0-9_]*$/'],
            // A BCP-47-ish code as Meta spells them: `en`, `fr`, `en_US`.
            'whatsapp.default_template_language' => ['string', 'present|string|max:16|regex:/^[a-zA-Z_]*$/'],
        ];
    }
};
