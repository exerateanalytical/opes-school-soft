<?php

declare(strict_types=1);

namespace App\Support\Expression;

/**
 * Tokeniser for the whitelisted grammar. Anything not on the whitelist is an
 * immediate, position-bearing rejection - quotes, semicolons, backticks,
 * dollar signs, backslashes and every other injection vehicle die here.
 */
final class Lexer
{
    /**
     * @return list<Token>
     */
    public static function tokenize(string $source): array
    {
        $tokens = [];
        $length = strlen($source);
        $i = 0;

        while ($i < $length) {
            $char = $source[$i];

            if ($char === ' ' || $char === "\t" || $char === "\n" || $char === "\r") {
                $i++;

                continue;
            }

            if ($char === '"' || $char === "'" || $char === '`') {
                throw ExpressionException::stringLiteral($i);
            }

            if (ctype_digit($char)) {
                $start = $i;
                while ($i < $length && ctype_digit($source[$i])) {
                    $i++;
                }
                // "12abc" or "1.5" must not silently split into two tokens.
                if ($i < $length && (ctype_alpha($source[$i]) || $source[$i] === '_' || $source[$i] === '.')) {
                    throw ExpressionException::unexpectedCharacter($source[$i], $i);
                }
                $tokens[] = new Token(TokenType::Number, substr($source, $start, $i - $start), $start);

                continue;
            }

            if (ctype_alpha($char) || $char === '_') {
                $start = $i;
                while ($i < $length && (ctype_alnum($source[$i]) || $source[$i] === '_' || $source[$i] === '.')) {
                    // A dot must be followed by an identifier character - a
                    // trailing or doubled dot is malformed, not a new token.
                    if ($source[$i] === '.') {
                        $next = $i + 1 < $length ? $source[$i + 1] : '';
                        if (! (ctype_alpha($next) || $next === '_')) {
                            throw ExpressionException::unexpectedCharacter('.', $i);
                        }
                    }
                    $i++;
                }
                $word = substr($source, $start, $i - $start);
                $tokens[] = match ($word) {
                    'and' => new Token(TokenType::And, $word, $start),
                    'or' => new Token(TokenType::Or, $word, $start),
                    'not' => new Token(TokenType::Not, $word, $start),
                    default => new Token(TokenType::Identifier, $word, $start),
                };

                continue;
            }

            $two = substr($source, $i, 2);

            if ($two === '&&') {
                $tokens[] = new Token(TokenType::And, $two, $i);
                $i += 2;

                continue;
            }

            if ($two === '||') {
                $tokens[] = new Token(TokenType::Or, $two, $i);
                $i += 2;

                continue;
            }

            if ($two === '==') {
                $tokens[] = new Token(TokenType::Eq, $two, $i);
                $i += 2;

                continue;
            }

            if ($two === '!=' || $two === '<>') {
                $tokens[] = new Token(TokenType::Neq, $two, $i);
                $i += 2;

                continue;
            }

            if ($two === '<=') {
                $tokens[] = new Token(TokenType::Lte, $two, $i);
                $i += 2;

                continue;
            }

            if ($two === '>=') {
                $tokens[] = new Token(TokenType::Gte, $two, $i);
                $i += 2;

                continue;
            }

            $type = match ($char) {
                '+' => TokenType::Plus,
                '-' => TokenType::Minus,
                '*' => TokenType::Star,
                '/' => TokenType::Slash,
                '(' => TokenType::LParen,
                ')' => TokenType::RParen,
                ',' => TokenType::Comma,
                '<' => TokenType::Lt,
                '>' => TokenType::Gt,
                '!' => TokenType::Not,
                default => null,
            };

            if ($type === null) {
                throw ExpressionException::unexpectedCharacter($char, $i);
            }

            $tokens[] = new Token($type, $char, $i);
            $i++;
        }

        $tokens[] = new Token(TokenType::End, '', $length);

        return $tokens;
    }
}
