<?php

declare(strict_types=1);

namespace App\Modules\Communication\Support\WhatsApp;

use App\Modules\Guardians\Domain\PhoneNumber;
use App\Modules\SchoolProfile\Actions\ReadSetting;

/**
 * Where the Meta credentials come from, and whether there are any.
 *
 * TWO sources, deliberately, in this order:
 *
 *   1. the audited `settings` store, written by /settings/whatsapp;
 *   2. config/whatsapp.php, i.e. the .env file.
 *
 * The settings store wins because the person who owns these credentials is
 * the principal, not the sysadmin: tokens expire and get rotated, and
 * requiring shell access to paste a new one would mean the channel stays
 * broken for a week. .env remains the way to pre-seed a deployment.
 *
 * Nothing in this class invents a value. Every getter returns null when the
 * school has not supplied one, and `isConfigured()` is the single truth about
 * whether a send may be attempted at all.
 */
final class WhatsAppConfig
{
    public const SETTING_ENABLED = 'whatsapp.enabled';

    public const SETTING_ACCESS_TOKEN = 'whatsapp.access_token';

    public const SETTING_PHONE_NUMBER_ID = 'whatsapp.phone_number_id';

    public const SETTING_BUSINESS_ACCOUNT_ID = 'whatsapp.business_account_id';

    public const SETTING_DEFAULT_TEMPLATE = 'whatsapp.default_template';

    public const SETTING_DEFAULT_TEMPLATE_LANGUAGE = 'whatsapp.default_template_language';

    public function __construct(private readonly ReadSetting $settings) {}

    /**
     * A sentinel, because `false` is a MEANINGFUL stored value here and null
     * would be indistinguishable from it. ReadSetting returns the fallback
     * only when the row is genuinely absent, so this is the one way to tell
     * "the operator switched it off" from "nobody has ever set this".
     */
    private const UNSET = "\0unset\0";

    /**
     * Once the settings row exists it is AUTHORITATIVE, including when it
     * says false - otherwise an operator hitting the off switch on an
     * instance whose .env says WHATSAPP_ENABLED=true would find the channel
     * still sending, which is the one failure this switch exists to prevent.
     *
     * The .env value is therefore the INSTALL default: the seed migration
     * copies it into the row, and from then on the admin screen owns it.
     */
    public function enabled(): bool
    {
        $stored = $this->settings->handle(self::SETTING_ENABLED, self::UNSET);

        if ($stored === self::UNSET) {
            return (bool) config('whatsapp.enabled', false);
        }

        return (bool) $stored;
    }

    public function accessToken(): ?string
    {
        return $this->resolve(self::SETTING_ACCESS_TOKEN, 'whatsapp.access_token');
    }

    public function phoneNumberId(): ?string
    {
        return $this->resolve(self::SETTING_PHONE_NUMBER_ID, 'whatsapp.phone_number_id');
    }

    public function businessAccountId(): ?string
    {
        return $this->resolve(self::SETTING_BUSINESS_ACCOUNT_ID, 'whatsapp.business_account_id');
    }

    public function defaultTemplate(): ?string
    {
        return $this->resolve(self::SETTING_DEFAULT_TEMPLATE, 'whatsapp.default_template');
    }

    public function defaultTemplateLanguage(): string
    {
        return $this->resolve(self::SETTING_DEFAULT_TEMPLATE_LANGUAGE, 'whatsapp.default_template_language')
            ?? 'en';
    }

    public function apiVersion(): string
    {
        $version = $this->resolve(null, 'whatsapp.api_version') ?? 'v21.0';

        // Meta's paths are `/v21.0/...`; tolerate a school typing `21.0`.
        return str_starts_with($version, 'v') ? $version : 'v'.$version;
    }

    public function timeout(): int
    {
        return max(1, (int) config('whatsapp.timeout', 15));
    }

    /**
     * The exact URL a text/template send POSTs to.
     *
     * Throws rather than returning a half-built URL, because a URL with an
     * empty phone number id in it (`/v21.0//messages`) would 404 at Meta and
     * be logged as a transport failure - which reads as "the network was
     * down" when the truth is "nobody ever configured this".
     */
    public function messagesEndpoint(): string
    {
        $phoneNumberId = $this->phoneNumberId();

        if ($phoneNumberId === null) {
            throw WhatsAppNotConfiguredException::missing('no phone number id');
        }

        $base = rtrim((string) config('whatsapp.base_url', 'https://graph.facebook.com'), '/');

        return $base.'/'.$this->apiVersion().'/'.$phoneNumberId.'/messages';
    }

    /**
     * True only when the channel could actually deliver something.
     *
     * `enabled` is part of the answer on purpose: a school that has pasted a
     * token but left the switch off has NOT configured the channel, and a
     * screen that told it otherwise would be reporting readiness the system
     * does not have.
     */
    public function isConfigured(): bool
    {
        return $this->enabled()
            && $this->accessToken() !== null
            && $this->phoneNumberId() !== null;
    }

    /**
     * The human explanation of why `isConfigured()` is false, or null when it
     * is true. This is the string the admin screen shows and the exception
     * carries, so there is exactly one wording of each problem.
     */
    public function missingReason(): ?string
    {
        $missing = [];

        if ($this->accessToken() === null) {
            $missing[] = 'no access token';
        }

        if ($this->phoneNumberId() === null) {
            $missing[] = 'no phone number id';
        }

        if ($missing !== []) {
            return implode(' and ', $missing);
        }

        return $this->enabled() ? null : 'the channel is switched off';
    }

    /** Refuse to go further unless a send could genuinely succeed. */
    public function assertConfigured(): void
    {
        if ($this->isConfigured()) {
            return;
        }

        if ($this->accessToken() === null || $this->phoneNumberId() === null) {
            throw WhatsAppNotConfiguredException::missing((string) $this->missingReason());
        }

        throw WhatsAppNotConfiguredException::disabled();
    }

    /**
     * Meta wants the destination as digits with NO leading `+` and no
     * punctuation, in full international form.
     *
     * The E.164 work itself is Guardians' PhoneNumber (07-students 7.7) - the
     * same normaliser that decides two admission forms describe one guardian.
     * A second parser here would eventually disagree with that one, and the
     * disagreement would show up as messages going to a number no guardian
     * record matches. So: normalise there, strip the `+` here, nothing else.
     */
    public static function toMetaRecipient(string $raw): string
    {
        $e164 = PhoneNumber::normalise($raw);

        if ($e164 === null) {
            throw WhatsAppNotConfiguredException::unusablePhone($raw);
        }

        return ltrim($e164, '+');
    }

    /**
     * Settings store first, config second. `$settingKey` may be null for
     * values that are deployment-level only (the API version).
     */
    private function resolve(?string $settingKey, string $configKey): ?string
    {
        if ($settingKey !== null) {
            $stored = $this->settings->handle($settingKey, null);

            if (is_string($stored) && trim($stored) !== '') {
                return trim($stored);
            }
        }

        $value = config($configKey);

        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        return null;
    }
}
