<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

/**
 * The one place the handle format is defined.
 *
 * A username is an addressing key - the messenger resolves a recipient by it -
 * so the rules have to be stated once and enforced identically by the account
 * screen, the Action and anything that lands later. A second copy of this
 * regex somewhere else is a bug waiting to happen: a handle accepted by one
 * door and rejected by another is a user who cannot be messaged.
 *
 * The rules, and why:
 *   - 3-32 characters. The column is VARCHAR(32); three is the floor at which
 *     a handle still identifies somebody.
 *   - letters, digits, underscore, dot only. No spaces (the compose field
 *     splits on nothing, but a trailing space is invisible), no `@` (that is
 *     how the messenger tells a handle from an email address).
 *   - must START with a letter. A leading digit reads as an ID and a leading
 *     dot hides the handle in listings sorted by name.
 *   - no consecutive dots, and no trailing dot. `amina..n` and `amina.` are
 *     the classic homograph tricks used to impersonate `amina.n`, which
 *     matters more here than usual because handles sit next to a blue tick.
 *
 * Comparison and storage are lower-case. `Amina.N` and `amina.n` are the same
 * person, so only one of them may exist.
 */
final class Username
{
    public const MAX_LENGTH = 32;

    public const MIN_LENGTH = 3;

    /**
     * Trim and lower-case. Call before comparing or storing anything.
     */
    public static function normalise(string $raw): string
    {
        return mb_strtolower(trim($raw));
    }

    /**
     * @return bool true when the NORMALISED form is a legal handle
     */
    public static function isValid(string $raw): bool
    {
        $value = self::normalise($raw);

        if (mb_strlen($value) < self::MIN_LENGTH || mb_strlen($value) > self::MAX_LENGTH) {
            return false;
        }

        if (str_contains($value, '..') || str_ends_with($value, '.') || str_ends_with($value, '_')) {
            return false;
        }

        return preg_match('/^[a-z][a-z0-9_.]*$/', $value) === 1;
    }

    /**
     * The human-readable reason a handle was refused, for a validation
     * message. Returns null when the handle is fine.
     */
    public static function violation(string $raw): ?string
    {
        if (self::isValid($raw)) {
            return null;
        }

        return __('opes.account.username_invalid', [
            'min' => self::MIN_LENGTH,
            'max' => self::MAX_LENGTH,
        ]);
    }
}
