<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain;

use InvalidArgumentException;

/**
 * docs/specs/10-documents.md 4.6 - "amount in words" for Receipt, Invoice,
 * Credit Note, Refund and Payslip.
 *
 * TWO INDEPENDENT IMPLEMENTATIONS by design: fr() and en() share no scale
 * tables and no helper methods, so a bug in one language cannot silently
 * shape the other. Each is pinned by its own golden table in
 * tests/Feature/Reporting/AmountInWordsTest.php.
 *
 * fr() renders the CAMEROONIAN (standard French) forms - quatre-vingts /
 * quatre-vingt-dix, soixante et onze - never the Belgian/Swiss septante /
 * nonante. Traditional orthography: spaces around "et", hyphens inside
 * compound tens ("trente-deux"), "cents"/"quatre-vingts" take their plural
 * s only when they end the numeral, and lose it before "mille" ("deux cent
 * mille") but keep it before the nouns "millions"/"milliards" ("deux cents
 * millions").
 *
 * en() renders SHORT SCALE (thousand, million, billion), hyphenated
 * tens-units ("twenty-one"), no "and" ("one hundred fifty thousand").
 *
 * Amounts are integer FCFA - XAF has no subunit, so there is no decimal
 * branch to get wrong. Range covers 0 .. 999 999 999 999; anything outside
 * throws rather than printing a wrong legal amount.
 */
final class AmountInWords
{
    private const MAX = 999_999_999_999;

    public static function render(int $amount, DocumentLanguage $language): string
    {
        return $language === DocumentLanguage::Fr ? self::fr($amount) : self::en($amount);
    }

    // ------------------------------------------------------------------ EN

    private const EN_UNITS = [
        'zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight',
        'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen',
        'sixteen', 'seventeen', 'eighteen', 'nineteen',
    ];

    private const EN_TENS = [
        2 => 'twenty', 3 => 'thirty', 4 => 'forty', 5 => 'fifty',
        6 => 'sixty', 7 => 'seventy', 8 => 'eighty', 9 => 'ninety',
    ];

    public static function en(int $amount): string
    {
        self::assertInRange($amount);

        if ($amount === 0) {
            return 'zero';
        }

        $parts = [];

        // % 1_000 is a no-op given assertInRange (billions max out at 999) -
        // it is here so the int<1, 999> contract below is provable, not
        // merely true.
        $billions = intdiv($amount, 1_000_000_000) % 1_000;
        $millions = intdiv($amount, 1_000_000) % 1_000;
        $thousands = intdiv($amount, 1_000) % 1_000;
        $rest = $amount % 1_000;

        if ($billions > 0) {
            $parts[] = self::enBelowThousand($billions).' billion';
        }

        if ($millions > 0) {
            $parts[] = self::enBelowThousand($millions).' million';
        }

        if ($thousands > 0) {
            $parts[] = self::enBelowThousand($thousands).' thousand';
        }

        if ($rest > 0) {
            $parts[] = self::enBelowThousand($rest);
        }

        return implode(' ', $parts);
    }

    /**
     * @param  int<1, 999>  $n
     */
    private static function enBelowThousand(int $n): string
    {
        $words = [];

        $hundreds = intdiv($n, 100);
        $remainder = $n % 100;

        if ($hundreds > 0) {
            $words[] = self::EN_UNITS[$hundreds].' hundred';
        }

        if ($remainder > 0) {
            if ($remainder < 20) {
                $words[] = self::EN_UNITS[$remainder];
            } else {
                $tens = self::EN_TENS[intdiv($remainder, 10)];
                $unit = $remainder % 10;
                $words[] = $unit === 0 ? $tens : $tens.'-'.self::EN_UNITS[$unit];
            }
        }

        return implode(' ', $words);
    }

    // ------------------------------------------------------------------ FR

    private const FR_UNITS = [
        'zéro', 'un', 'deux', 'trois', 'quatre', 'cinq', 'six', 'sept', 'huit',
        'neuf', 'dix', 'onze', 'douze', 'treize', 'quatorze', 'quinze', 'seize',
        'dix-sept', 'dix-huit', 'dix-neuf',
    ];

    private const FR_TENS = [
        2 => 'vingt', 3 => 'trente', 4 => 'quarante', 5 => 'cinquante', 6 => 'soixante',
    ];

    public static function fr(int $amount): string
    {
        self::assertInRange($amount);

        if ($amount === 0) {
            return 'zéro';
        }

        // % 1_000 mirrors en(): provably int<0, 999> for the helpers below.
        $milliards = intdiv($amount, 1_000_000_000) % 1_000;
        $millions = intdiv($amount, 1_000_000) % 1_000;
        $milliers = intdiv($amount, 1_000) % 1_000;
        $reste = $amount % 1_000;

        $parts = [];

        if ($milliards > 0) {
            // milliard is a NOUN: "un milliard", "deux milliards" - and being
            // a noun, a preceding "cents"/"quatre-vingts" keeps its s.
            $parts[] = self::frBelowThousand($milliards, true)
                .($milliards > 1 ? ' milliards' : ' milliard');
        }

        if ($millions > 0) {
            $parts[] = self::frBelowThousand($millions, true)
                .($millions > 1 ? ' millions' : ' million');
        }

        if ($milliers > 0) {
            // mille is an invariable numeral ADJECTIVE: never "un mille",
            // never "milles", and it strips the plural s off "cents" and
            // "quatre-vingts" ("deux cent mille", "quatre-vingt mille").
            $parts[] = $milliers === 1 ? 'mille' : self::frBelowThousand($milliers, false).' mille';
        }

        if ($reste > 0) {
            $parts[] = self::frBelowThousand($reste, true);
        }

        return implode(' ', $parts);
    }

    /**
     * @param  int<1, 999>  $n
     * @param  bool  $terminal  true when nothing but a NOUN (million,
     *                          milliard, or the end of the numeral) follows -
     *                          the condition under which "cents" and
     *                          "quatre-vingts" keep their plural s.
     */
    private static function frBelowThousand(int $n, bool $terminal): string
    {
        $words = [];

        $hundreds = intdiv($n, 100);
        $remainder = $n % 100;

        if ($hundreds === 1) {
            $words[] = 'cent';
        } elseif ($hundreds > 1) {
            $words[] = self::FR_UNITS[$hundreds]
                .($remainder === 0 && $terminal ? ' cents' : ' cent');
        }

        if ($remainder > 0) {
            $words[] = self::frBelowHundred($remainder, $terminal);
        }

        return implode(' ', $words);
    }

    /**
     * @param  int<1, 99>  $n
     */
    private static function frBelowHundred(int $n, bool $terminal): string
    {
        if ($n < 20) {
            return self::FR_UNITS[$n];
        }

        if ($n < 70) {
            $tens = self::FR_TENS[intdiv($n, 10)];
            $unit = $n % 10;

            if ($unit === 0) {
                return $tens;
            }

            // 21, 31, 41, 51, 61: "et un", with spaces (traditional
            // orthography); every other unit hyphenates.
            return $unit === 1 ? $tens.' et un' : $tens.'-'.self::FR_UNITS[$unit];
        }

        if ($n < 80) {
            // 70-79 count from soixante: soixante-dix, soixante et onze,
            // soixante-douze ... soixante-dix-neuf.
            return $n === 71 ? 'soixante et onze' : 'soixante-'.self::FR_UNITS[$n - 60];
        }

        // 80-99 - the Cameroonian quatre-vingts forms, never "octante" or
        // "nonante": quatre-vingts, quatre-vingt-un (no "et"),
        // quatre-vingt-dix, quatre-vingt-onze ... quatre-vingt-dix-neuf.
        if ($n === 80) {
            return $terminal ? 'quatre-vingts' : 'quatre-vingt';
        }

        return 'quatre-vingt-'.self::FR_UNITS[$n - 80];
    }

    private static function assertInRange(int $amount): void
    {
        if ($amount < 0 || $amount > self::MAX) {
            throw new InvalidArgumentException(
                "Amount {$amount} is outside the writable range 0..".self::MAX
                .' FCFA; refusing to print a wrong legal amount.'
            );
        }
    }
}
