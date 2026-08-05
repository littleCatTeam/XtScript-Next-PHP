<?php

declare(strict_types=1);

namespace XtScript\Parser;

enum TokenType: string
{
    case Variable = 'variable';
    case Number = 'number';
    case String = 'string';
    case Regex = 'regex';
    case Identifier = 'identifier';
    case Operator = 'operator';
    case LeftParen = 'left_paren';
    case RightParen = 'right_paren';
    case LeftBracket = 'left_bracket';
    case RightBracket = 'right_bracket';
    case LeftBrace = 'left_brace';
    case RightBrace = 'right_brace';
    case Comma = 'comma';
    case Colon = 'colon';
    case Question = 'question';
    case Eof = 'eof';
}
