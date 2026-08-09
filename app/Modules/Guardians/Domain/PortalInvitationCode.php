<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Domain;

/**
 * The invitation code itself: generation, normalisation, hashing. One class
 * so the writing side (IssuePortalInvitation) and the reading side
 * (ActivatePortalAccount) cannot disagree about what a code looks like.
 *
 * 00-core 9.3 forbids assuming SMTP exists, so the code is handed over out of
 * band - a printed slip, WhatsApp, read out at the counter - and is designed
 * for that channel: 12 characters from an alphabet with no 0/O, 1/I/L
 * ambiguity, grouped in fours. 31^12 is about 7.9e17 codes; against the
 * database's UNIQUE code_hash and the invitation's short expiry, online
 * guessing is not a realistic path.
 *
 * Only the SHA-256 of the normalised form is ever stored. Normalisation
 * uppercases and strips everything outside the alphabet, so a code typed
 * with or without its dashes, in any case, redeems identically.
 */
final class PortalInvitationCode
{
    /** No 0, O, 1, I or L: every character survives handwriting. */
    public const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public const LENGTH = 12;

    /** e.g. "7XKM-Q2NA-9RWD" */
    public static function generate(): string
    {
        $alphabet = self::ALPHABET;
        $max = strlen($alphabet) - 1;
        $raw = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            $raw .= $alphabet[random_int(0, $max)];
        }

        return implode('-', str_split($raw, 4));
    }

    /** Uppercase, alphabet characters only - the canonical form that is hashed. */
    public static function normalise(string $code): string
    {
        $upper = strtoupper($code);
        $kept = preg_replace('/[^'.self::ALPHABET.']/', '', $upper);

        return $kept ?? '';
    }

    public static function hash(string $code): string
    {
        return hash('sha256', self::normalise($code));
    }
}
