<?php

declare(strict_types=1);

namespace App\Support\Expression;

/**
 * Dot-flattens a nested event payload into the flat variable map the
 * expression grammar resolves against. Associative arrays flatten into
 * dotted paths; lists (the `iterates_over` collections) and partner tuples
 * (`['type' => ..., 'id' => ...]`) are kept whole at their path.
 */
final class Payload
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function flatten(array $payload, string $prefix = ''): array
    {
        $flat = [];

        foreach ($payload as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value) && ! array_is_list($value) && ! self::isPartnerTuple($value)) {
                $flat = array_merge($flat, self::flatten($value, $path));

                continue;
            }

            $flat[$path] = $value;
        }

        return $flat;
    }

    /**
     * The int/bool subset - what an amount or condition expression may see.
     *
     * @param  array<string, mixed>  $flat
     * @return array<string, int|bool>
     */
    public static function scalars(array $flat): array
    {
        $scalars = [];

        foreach ($flat as $path => $value) {
            if (is_int($value) || is_bool($value)) {
                $scalars[$path] = $value;
            }
        }

        return $scalars;
    }

    /**
     * The int/bool/string subset - what a label template may see.
     *
     * @param  array<string, mixed>  $flat
     * @return array<string, int|bool|string>
     */
    public static function printables(array $flat): array
    {
        $printables = [];

        foreach ($flat as $path => $value) {
            if (is_int($value) || is_bool($value) || is_string($value)) {
                $printables[$path] = $value;
            }
        }

        return $printables;
    }

    /**
     * @param  array<mixed>  $value
     */
    private static function isPartnerTuple(array $value): bool
    {
        return count($value) === 2
            && array_key_exists('type', $value)
            && array_key_exists('id', $value);
    }
}
