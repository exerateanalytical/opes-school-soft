<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain;

use InvalidArgumentException;

/**
 * docs/specs/10-documents.md 4.3 - renders a DocumentSeries.format string
 * ('{school}/{year}/{code}/{serial:6}') into the human-facing serial.
 *
 * Pure string work; the NUMBER comes exclusively from SequenceAllocator
 * inside the render transaction. This class must never see the sequences
 * table - a formatter that could allocate would be the second numbering path
 * 00-core 12 forbids.
 */
final class SeriesFormatter
{
    /**
     * @param  string  $format  the series' declared format
     * @param  string  $school  SchoolProfile.short_code
     * @param  string  $scopeValue  the counter-scope discriminator: '2026' for
     *                              year scopes, '2026-04-12' for day scope,
     *                              '2026-04' for payroll_month, section id for
     *                              section scope, '' for global
     * @param  string  $code  DocumentSeries.code
     * @param  int  $number  the allocated integer
     * @param  int  $defaultPadding  DocumentSeries.padding, used when the
     *                               format writes bare {serial}
     */
    public static function format(
        string $format,
        string $school,
        string $scopeValue,
        string $code,
        int $number,
        int $defaultPadding,
    ): string {
        $serial = (string) preg_replace_callback(
            '/\{serial(?::(\d+))?\}/',
            static fn (array $m): string => str_pad(
                (string) $number,
                isset($m[1]) ? (int) $m[1] : $defaultPadding,
                '0',
                STR_PAD_LEFT,
            ),
            $format,
        );

        $serial = str_replace(
            ['{school}', '{code}', '{year}', '{date}', '{month}'],
            [$school, $code, $scopeValue, $scopeValue, $scopeValue],
            $serial,
        );

        if (str_contains($serial, '{') || str_contains($serial, '}')) {
            throw new InvalidArgumentException(
                "Series format [{$format}] contains a token this formatter does not know; "
                .'an unrendered brace in a legal document number is a defect, not a literal.'
            );
        }

        return $serial;
    }
}
