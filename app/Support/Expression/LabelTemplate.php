<?php

declare(strict_types=1);

namespace App\Support\Expression;

/**
 * The tiny companion to the expression grammar for `label_expression`
 * fields (02-accounting.md §11.1: "templated from the payload"). Literal
 * text plus `{variable.path}` placeholders - no arithmetic, no logic, no
 * function calls, and the same schema whitelist as the expressions, so a
 * label can never reach data an expression could not.
 */
final class LabelTemplate
{
    private const PLACEHOLDER = '/\{([A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*)\}/';

    /**
     * Save-time validation: every placeholder must be a declared payload
     * variable, and no malformed brace may survive.
     *
     * @param  list<string>  $allowedVariables
     * @return list<string> the placeholders used
     */
    public static function validate(string $template, array $allowedVariables): array
    {
        $stripped = (string) preg_replace(self::PLACEHOLDER, '', $template);

        if (str_contains($stripped, '{') || str_contains($stripped, '}')) {
            throw ExpressionException::typeMismatch(
                "label template '{$template}' contains a malformed placeholder"
            );
        }

        preg_match_all(self::PLACEHOLDER, $template, $matches);
        $used = [];

        foreach ($matches[1] as $name) {
            if (! in_array($name, $allowedVariables, true)) {
                throw ExpressionException::unknownVariable($name, (int) strpos($template, '{'.$name.'}'));
            }

            $used[] = $name;
        }

        return array_values(array_unique($used));
    }

    /**
     * @param  array<string, int|string|bool>  $variables
     */
    public static function render(string $template, array $variables): string
    {
        return (string) preg_replace_callback(
            self::PLACEHOLDER,
            static function (array $matches) use ($variables): string {
                $name = $matches[1];

                if (! array_key_exists($name, $variables)) {
                    throw ExpressionException::missingValue($name);
                }

                $value = $variables[$name];

                if (is_bool($value)) {
                    return $value ? 'true' : 'false';
                }

                return (string) $value;
            },
            $template,
        );
    }
}
