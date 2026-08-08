<?php

declare(strict_types=1);

namespace App\Support\Expression;

/**
 * Hand-written recursive-descent parser for the ONE whitelisted grammar
 * shared by posting-rule expressions (02-accounting.md §11.1) and payroll
 * formulas (05-hr-payroll.md §5.4).
 *
 *   or      := and ( ('or'|'||') and )*
 *   and     := cmp ( ('and'|'&&') cmp )*
 *   cmp     := sum ( ('<'|'<='|'>'|'>='|'=='|'!=') sum )?
 *   sum     := term ( ('+'|'-') term )*
 *   term    := factor ( ('*'|'/') factor )*
 *   factor  := NUMBER | VARIABLE | '(' or ')' | '-' factor | ('not'|'!') factor
 *            | ('min'|'max') '(' or ',' or ')' | 'abs' '(' or ')'
 *
 * No other function names, no string literals, no assignment, no property
 * access beyond dotted VARIABLE names checked against the declared schema,
 * no dynamic evaluation of any kind. never eval().
 */
final class Parser
{
    private const MAX_DEPTH = 64;

    /** @var list<Token> */
    private array $tokens;

    private int $index = 0;

    private int $depth = 0;

    private function __construct(string $source)
    {
        if (trim($source) === '') {
            throw ExpressionException::emptyExpression();
        }

        $this->tokens = Lexer::tokenize($source);
    }

    public static function parse(string $source): Node
    {
        $parser = new self($source);
        $node = $parser->parseOr();
        $parser->expect(TokenType::End);

        return $node;
    }

    private function parseOr(): Node
    {
        $node = $this->parseAnd();

        while ($this->current()->type === TokenType::Or) {
            $this->advance();
            $node = new BinaryNode('or', $node, $this->parseAnd());
        }

        return $node;
    }

    private function parseAnd(): Node
    {
        $node = $this->parseComparison();

        while ($this->current()->type === TokenType::And) {
            $this->advance();
            $node = new BinaryNode('and', $node, $this->parseComparison());
        }

        return $node;
    }

    private function parseComparison(): Node
    {
        $node = $this->parseSum();

        $op = match ($this->current()->type) {
            TokenType::Lt => '<',
            TokenType::Lte => '<=',
            TokenType::Gt => '>',
            TokenType::Gte => '>=',
            TokenType::Eq => '==',
            TokenType::Neq => '!=',
            default => null,
        };

        if ($op !== null) {
            $this->advance();

            return new BinaryNode($op, $node, $this->parseSum());
        }

        return $node;
    }

    private function parseSum(): Node
    {
        $node = $this->parseTerm();

        while (true) {
            $type = $this->current()->type;

            if ($type === TokenType::Plus) {
                $this->advance();
                $node = new BinaryNode('+', $node, $this->parseTerm());
            } elseif ($type === TokenType::Minus) {
                $this->advance();
                $node = new BinaryNode('-', $node, $this->parseTerm());
            } else {
                return $node;
            }
        }
    }

    private function parseTerm(): Node
    {
        $node = $this->parseFactor();

        while (true) {
            $type = $this->current()->type;

            if ($type === TokenType::Star) {
                $this->advance();
                $node = new BinaryNode('*', $node, $this->parseFactor());
            } elseif ($type === TokenType::Slash) {
                $position = $this->current()->position;
                $this->advance();
                $divisor = $this->parseFactor();

                // 05-hr-payroll.md §5.4: division by a LITERAL zero is
                // rejected at parse, not discovered at run time.
                if ($divisor instanceof NumberNode && $divisor->value === 0) {
                    throw ExpressionException::divisionByLiteralZero($position);
                }

                $node = new BinaryNode('/', $node, $divisor);
            } else {
                return $node;
            }
        }
    }

    private function parseFactor(): Node
    {
        $this->enter();
        $token = $this->current();

        $node = match (true) {
            $token->type === TokenType::Number => $this->parseNumber(),
            $token->type === TokenType::Minus => $this->parsePrefix('neg'),
            $token->type === TokenType::Not => $this->parsePrefix('not'),
            $token->type === TokenType::LParen => $this->parseGroup(),
            $token->type === TokenType::Identifier => $this->parseIdentifier(),
            default => throw ExpressionException::unexpectedToken(
                $token->describe(),
                $token->position,
                'a number, a variable, min/max/abs, or an opening parenthesis',
            ),
        };

        $this->leave();

        return $node;
    }

    private function parseNumber(): Node
    {
        $token = $this->current();
        $this->advance();

        // Integer literals only; a literal that does not survive the
        // int round-trip has overflowed PHP_INT_MAX.
        $value = (int) $token->value;
        $normalized = ltrim($token->value, '0');

        if ($normalized === '') {
            $normalized = '0';
        }

        if ((string) $value !== $normalized) {
            throw ExpressionException::overflow();
        }

        return new NumberNode($value);
    }

    private function parsePrefix(string $op): Node
    {
        $this->advance();

        /** @var 'neg'|'not' $op */
        return new UnaryNode($op, $this->parseFactor());
    }

    private function parseGroup(): Node
    {
        $this->advance();
        $node = $this->parseOr();
        $this->expect(TokenType::RParen);

        return $node;
    }

    private function parseIdentifier(): Node
    {
        $token = $this->current();
        $this->advance();

        // An identifier followed by '(' is a call attempt. Only min, max and
        // abs exist; system(), exec(), pow() and friends die here by name.
        if ($this->current()->type === TokenType::LParen) {
            return $this->parseCall($token);
        }

        return new VariableNode($token->value, $token->position);
    }

    private function parseCall(Token $name): Node
    {
        if (! in_array($name->value, ['min', 'max', 'abs'], true)) {
            throw ExpressionException::functionNotAllowed($name->value, $name->position);
        }

        $arity = $name->value === 'abs' ? 1 : 2;

        $this->expect(TokenType::LParen);
        $arguments = [$this->parseOr()];

        while ($this->current()->type === TokenType::Comma) {
            $this->advance();
            $arguments[] = $this->parseOr();
        }

        $this->expect(TokenType::RParen);

        if (count($arguments) !== $arity) {
            throw ExpressionException::wrongArity($name->value, $arity, count($arguments), $name->position);
        }

        /** @var 'min'|'max'|'abs' $callee */
        $callee = $name->value;

        return new CallNode($callee, $arguments);
    }

    private function current(): Token
    {
        return $this->tokens[$this->index];
    }

    private function advance(): void
    {
        if ($this->index < count($this->tokens) - 1) {
            $this->index++;
        }
    }

    private function expect(TokenType $type): void
    {
        $token = $this->current();

        if ($token->type !== $type) {
            throw ExpressionException::unexpectedToken($token->describe(), $token->position, $type->value);
        }

        $this->advance();
    }

    private function enter(): void
    {
        if (++$this->depth > self::MAX_DEPTH) {
            throw ExpressionException::tooDeep(self::MAX_DEPTH);
        }
    }

    private function leave(): void
    {
        $this->depth--;
    }
}
