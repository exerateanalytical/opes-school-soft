<?php

declare(strict_types=1);

namespace App\Modules\Communication\Support;

/**
 * The one merge-field renderer. `{student_name}` style, single braces, the
 * spelling the 300005 migration's comment already committed to.
 *
 * Two rules that matter more than they look:
 *  - an UNKNOWN placeholder is left standing rather than blanked, so the
 *    office sees "{amount_due}" in the outbox and knows the caller forgot a
 *    variable; a silently empty sentence would just look like a typo;
 *  - values are inserted raw. The body is delivered as SMS/e-mail text and
 *    is escaped by Blade at display time - escaping here would put &amp; in
 *    a parent's text message.
 */
final class MergeFields
{
    public const PATTERN = '/\{([a-zA-Z0-9_.]+)\}/';

    private function __construct() {}

    /**
     * @param  array<string, mixed>  $values
     */
    public static function render(string $template, array $values): string
    {
        $rendered = preg_replace_callback(
            self::PATTERN,
            static function (array $match) use ($values): string {
                $key = $match[1];

                if (! array_key_exists($key, $values)) {
                    return $match[0];
                }

                $value = $values[$key];

                if ($value === null || is_bool($value) || is_array($value) || is_object($value)) {
                    return is_object($value) && method_exists($value, '__toString')
                        ? (string) $value
                        : $match[0];
                }

                return (string) $value;
            },
            $template,
        );

        return $rendered ?? $template;
    }

    /**
     * Placeholders actually used in a body - what SaveMessageTemplate checks
     * the declared variable list against.
     *
     * @return list<string>
     */
    public static function extract(string $template): array
    {
        preg_match_all(self::PATTERN, $template, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * Placeholders left unresolved after a render - the outbox's early
     * warning that a caller under-supplied its variables.
     *
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    public static function missing(string $template, array $values): array
    {
        return array_values(array_filter(
            self::extract($template),
            static fn (string $name): bool => ! array_key_exists($name, $values),
        ));
    }
}
