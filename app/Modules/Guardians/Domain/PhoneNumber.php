<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Domain;

/**
 * E.164 normalisation for guardian phone numbers.
 *
 * Exists because docs/specs/07-students.md 7.7 makes the normalised phone the
 * SECOND tier of the duplicate-match key. "Exact" match on a raw phone column
 * is worthless in Cameroon, where the same handset is written `677 12 34 56`,
 * `+237677123456`, `00237 677123456` and `237-677-123-456` on four different
 * admission forms. Without normalisation, tier 2 never fires and every one of
 * those four forms creates a fresh guardian.
 *
 * Pure: no Laravel, no libphonenumber. The rules encoded are only the ones the
 * spec needs - strip formatting, resolve the international prefix, default to
 * the Cameroonian country code for a bare national number.
 */
final class PhoneNumber
{
    /** Cameroon. `Guardian.nationality` defaults to CM for the same reason. */
    public const DEFAULT_COUNTRY_CODE = '237';

    /**
     * Returns the number in E.164 (`+237677123456`), or null if there are no
     * digits at all to work with.
     *
     * A null return is not an error: it means "this string cannot participate
     * in tier-2 matching", and the caller degrades to tier 3 rather than
     * matching every unparseable number against every other one.
     */
    public static function normalise(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        // `00` is the ITU international access code used across francophone
        // Africa; `+` is the E.164 spelling of the same thing. Fold before
        // stripping punctuation so that `00237...` is not mistaken for a
        // national number that happens to start with two zeroes.
        $trimmed = ltrim(trim($raw));
        $hasInternationalPrefix = str_starts_with($trimmed, '+') || str_starts_with($trimmed, '00');

        $digits = preg_replace('/\D+/', '', $trimmed) ?? '';

        if ($hasInternationalPrefix && str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if ($digits === '') {
            return null;
        }

        if ($hasInternationalPrefix) {
            return '+'.$digits;
        }

        // A bare national number. Cameroonian mobile numbers are 9 digits and
        // are frequently written with a leading 0 copied from landline habit;
        // that 0 is not part of the E.164 subscriber number.
        $digits = ltrim($digits, '0');

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, self::DEFAULT_COUNTRY_CODE) && strlen($digits) > 9) {
            return '+'.$digits;
        }

        return '+'.self::DEFAULT_COUNTRY_CODE.$digits;
    }
}
