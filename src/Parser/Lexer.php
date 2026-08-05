<?php

declare(strict_types=1);

namespace XtScript\Parser;

use XtScript\Exception\SyntaxErrorException;

final class Lexer
{
    /** @return list<Token> */
    public function tokenize(string $source): array
    {
        $tokens = [];
        $length = strlen($source);
        $offset = 0;

        while ($offset < $length) {
            $char = $source[$offset];
            if (self::isWhitespace($char)) {
                ++$offset;
                continue;
            }

            if ($char === '$') {
                $start = $offset++;
                while ($offset < $length && (self::isAlphaNumeric($source[$offset]) || $source[$offset] === '.')) {
                    ++$offset;
                }
                $lexeme = substr($source, $start, $offset - $start);
                if (preg_match('/^\$[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*$/D', $lexeme) !== 1) {
                    throw new SyntaxErrorException('Invalid variable token.');
                }
                $tokens[] = new Token(TokenType::Variable, $lexeme, $lexeme, $start);
                continue;
            }

            if ($char === '"' || $char === "'") {
                $tokens[] = $this->readString($source, $offset, $char);
                continue;
            }

            if (self::isDigit($char) || ($char === '.' && $offset + 1 < $length && self::isDigit($source[$offset + 1]))) {
                $tokens[] = $this->readNumber($source, $offset);
                continue;
            }

            if (self::isAlpha($char) || $char === '_') {
                $start = $offset++;
                while ($offset < $length && self::isAlphaNumeric($source[$offset])) {
                    ++$offset;
                }
                $lexeme = substr($source, $start, $offset - $start);
                $lower = strtolower($lexeme);
                $tokens[] = in_array($lower, ['and', 'or', 'not', 'is', 'in', 'matches'], true)
                    ? new Token(TokenType::Operator, $lower, $lower, $start)
                    : new Token(TokenType::Identifier, $lexeme, $this->identifierLiteral($lexeme), $start);
                continue;
            }

            $three = substr($source, $offset, 3);
            $two = substr($source, $offset, 2);
            if (in_array($three, ['===', '!=='], true)) {
                $tokens[] = new Token(TokenType::Operator, $three, $three, $offset);
                $offset += 3;
                continue;
            }
            if (in_array($two, ['>=', '<=', '==', '!=', '??'], true)) {
                $tokens[] = new Token(TokenType::Operator, $two, $two, $offset);
                $offset += 2;
                continue;
            }
            if ($char === '/' && $this->expectsValue($tokens)) {
                $tokens[] = $this->readRegex($source, $offset);
                continue;
            }
            if (str_contains('+-*/%><|~', $char)) {
                $tokens[] = new Token(TokenType::Operator, $char, $char, $offset++);
                continue;
            }
            if ($char === '(') {
                $tokens[] = new Token(TokenType::LeftParen, $char, null, $offset++);
                continue;
            }
            if ($char === ')') {
                $tokens[] = new Token(TokenType::RightParen, $char, null, $offset++);
                continue;
            }
            if ($char === '[') {
                $tokens[] = new Token(TokenType::LeftBracket, $char, null, $offset++);
                continue;
            }
            if ($char === ']') {
                $tokens[] = new Token(TokenType::RightBracket, $char, null, $offset++);
                continue;
            }
            if ($char === '{') {
                $tokens[] = new Token(TokenType::LeftBrace, $char, null, $offset++);
                continue;
            }
            if ($char === '}') {
                $tokens[] = new Token(TokenType::RightBrace, $char, null, $offset++);
                continue;
            }
            if ($char === ',') {
                $tokens[] = new Token(TokenType::Comma, $char, null, $offset++);
                continue;
            }
            if ($char === ':') {
                $tokens[] = new Token(TokenType::Colon, $char, null, $offset++);
                continue;
            }
            if ($char === '?') {
                $tokens[] = new Token(TokenType::Question, $char, null, $offset++);
                continue;
            }

            throw new SyntaxErrorException(sprintf('Unexpected character "%s" at offset %d.', $char, $offset));
        }

        $tokens[] = new Token(TokenType::Eof, '', null, $offset);
        return $tokens;
    }

    /** @param list<Token> $tokens */
    private function expectsValue(array $tokens): bool
    {
        if ($tokens === []) {
            return true;
        }

        $previous = $tokens[count($tokens) - 1];
        return in_array($previous->type, [
            TokenType::Operator,
            TokenType::LeftParen,
            TokenType::LeftBracket,
            TokenType::LeftBrace,
            TokenType::Comma,
            TokenType::Colon,
            TokenType::Question,
        ], true);
    }

    private function readRegex(string $source, int &$offset): Token
    {
        $start = $offset++;
        $length = strlen($source);
        $escaped = false;
        $inClass = false;

        while ($offset < $length) {
            $char = $source[$offset++];
            if ($escaped) {
                $escaped = false;
                continue;
            }
            if ($char === '\\') {
                $escaped = true;
                continue;
            }
            if ($char === '[') {
                $inClass = true;
                continue;
            }
            if ($char === ']' && $inClass) {
                $inClass = false;
                continue;
            }
            if ($char === '/' && !$inClass) {
                while ($offset < $length && self::isAlpha($source[$offset])) {
                    ++$offset;
                }
                $lexeme = substr($source, $start, $offset - $start);
                return new Token(TokenType::Regex, $lexeme, $lexeme, $start);
            }
        }

        throw new SyntaxErrorException('Unterminated regex literal.');
    }

    private function readString(string $source, int &$offset, string $quote): Token
    {
        $start = $offset++;
        $value = '';
        $length = strlen($source);
        while ($offset < $length) {
            $char = $source[$offset++];
            if ($char === $quote) {
                return new Token(TokenType::String, substr($source, $start, $offset - $start), $value, $start);
            }
            if ($char === '\\' && $offset < $length) {
                $escaped = $source[$offset++];
                $value .= match ($escaped) {
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    '\\' => '\\',
                    '"' => '"',
                    "'" => "'",
                    default => $escaped,
                };
                continue;
            }
            $value .= $char;
        }

        throw new SyntaxErrorException('Unterminated string literal.');
    }

    private function readNumber(string $source, int &$offset): Token
    {
        $start = $offset;
        $length = strlen($source);
        while ($offset < $length && (self::isDigit($source[$offset]) || $source[$offset] === '.')) {
            ++$offset;
        }
        if ($offset < $length && ($source[$offset] === 'e' || $source[$offset] === 'E')) {
            ++$offset;
            if ($offset < $length && ($source[$offset] === '+' || $source[$offset] === '-')) {
                ++$offset;
            }
            while ($offset < $length && self::isDigit($source[$offset])) {
                ++$offset;
            }
        }

        $lexeme = substr($source, $start, $offset - $start);
        if (!is_numeric($lexeme)) {
            throw new SyntaxErrorException(sprintf('Invalid numeric literal "%s".', $lexeme));
        }

        $literal = strpbrk($lexeme, '.eE') === false ? (int) $lexeme : (float) $lexeme;
        return new Token(TokenType::Number, $lexeme, $literal, $start);
    }

    private function identifierLiteral(string $identifier): mixed
    {
        return match (strtolower($identifier)) {
            'true' => true,
            'false' => false,
            'null' => null,
            default => $identifier,
        };
    }

    private static function isWhitespace(string $char): bool
    {
        return $char === ' ' || $char === "\t" || $char === "\n" || $char === "\r" || $char === "\f" || $char === "\v";
    }

    private static function isDigit(string $char): bool
    {
        return $char >= '0' && $char <= '9';
    }

    private static function isAlpha(string $char): bool
    {
        return ($char >= 'a' && $char <= 'z') || ($char >= 'A' && $char <= 'Z');
    }

    private static function isAlphaNumeric(string $char): bool
    {
        return self::isAlpha($char) || self::isDigit($char) || $char === '_';
    }
}
