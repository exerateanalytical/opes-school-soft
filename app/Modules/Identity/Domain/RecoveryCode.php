<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

use Random\RandomException;

/**
 * A break-glass recovery code (docs/specs/00-core.md 9.3).
 *
 * Crockford-style alphabet with 0, O, 1, I, L and U removed: the code is read
 * off a sheet of paper in a school office by someone who has just lost access,
 * and 0-versus-O is exactly the failure you do not want at that moment. U is
 * dropped as well so the alphabet cannot spell anything unfortunate.
 *
 * Pure PHP with no framework dependency, so it can be unit-tested without
 * booting the container.
 */
final readonly class RecoveryCode
{
    public const ALPHABET = '23456789ABCDEFGHJKMNPQRSTVWXYZ'; // 30 characters

    public const GROUPS = 4;

    public const GROUP_LENGTH = 5;

    private function __construct(private string $normalised)
    {
    }

    /**
     * @throws RandomException
     */
    public static function generate(): self
    {
        $length = self::GROUPS * self::GROUP_LENGTH;
        $max = strlen(self::ALPHABET) - 1;
        $out = '';

        for ($i = 0; $i < $length; $i++) {
            $out .= self::ALPHABET[random_int(0, $max)];
        }

        return new self($out);
    }

    public static function fromNormalised(string $normalised): self
    {
        return new self($normalised);
    }

    /** Tolerant of case, dashes, spaces - however it was written down. */
    public static function normalise(string $input): string
    {
        return strtoupper((string) preg_replace('/[^0-9A-Za-z]/', '', $input));
    }

    public static function entropyBits(): int
    {
        return (int) floor(
            self::GROUPS * self::GROUP_LENGTH * log(strlen(self::ALPHABET), 2)
        );
    }

    public function normalised(): string
    {
        return $this->normalised;
    }

    public function formatted(): string
    {
        return implode('-', str_split($this->normalised, self::GROUP_LENGTH));
    }
}
