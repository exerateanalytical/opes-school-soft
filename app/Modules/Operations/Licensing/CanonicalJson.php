<?php

declare(strict_types=1);

namespace App\Modules\Operations\Licensing;

/**
 * The canonical-JSON form a licence payload is signed over
 * (docs/specs/08-operations.md §4.3): keys sorted ordinal (byte order),
 * compact output, no escaped slashes, no escaped unicode, integers and
 * `null` unquoted. Signer and verifier MUST produce byte-identical strings
 * from the same logical payload or every signature check becomes a coin
 * toss; this class is the single place that form is defined.
 */
final class CanonicalJson
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function encode(array $payload): string
    {
        return json_encode(
            self::normalise($payload),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Sorts object keys ordinally (strcmp, i.e. byte order - NOT locale or
     * natural order) at every depth. Lists keep their element order: a
     * sequence is data, an object is a set of named fields.
     */
    private static function normalise(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(static fn (mixed $item): mixed => self::normalise($item), $value);
        }

        $keyed = [];

        foreach ($value as $key => $item) {
            $keyed[(string) $key] = self::normalise($item);
        }

        ksort($keyed, SORT_STRING);

        return $keyed;
    }
}
