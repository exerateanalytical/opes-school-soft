<?php

declare(strict_types=1);

namespace App\Support\Expression;

enum TokenType: string
{
    case Number = 'number';
    case Identifier = 'identifier';
    case Plus = "'+'";
    case Minus = "'-'";
    case Star = "'*'";
    case Slash = "'/'";
    case LParen = "'('";
    case RParen = "')'";
    case Comma = "','";
    case Lt = "'<'";
    case Lte = "'<='";
    case Gt = "'>'";
    case Gte = "'>='";
    case Eq = "'=='";
    case Neq = "'!='";
    case And = "'and'";
    case Or = "'or'";
    case Not = "'not'";
    case End = 'end of expression';
}
