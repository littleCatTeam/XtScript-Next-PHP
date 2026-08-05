<?php

declare(strict_types=1);

namespace XtScript\Parser;

final class ExpressionSyntax
{
    public static function isFormal(string $expression): bool
    {
        if (preg_match('/^\$[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*$/D', $expression) === 1) {
            return true;
        }
        if (preg_match('/^(?:true|false|null|-?(?:\d+(?:\.\d+)?|\.\d+))$/iD', $expression) === 1) {
            return true;
        }
        if (self::isRegexLiteral($expression)) {
            return true;
        }
        if ((str_starts_with($expression, '"') && str_ends_with($expression, '"'))
            || (str_starts_with($expression, "'") && str_ends_with($expression, "'"))) {
            return true;
        }
        if ((str_starts_with($expression, '(') && str_ends_with($expression, ')'))
            || (str_starts_with($expression, '[') && str_ends_with($expression, ']'))
            || (str_starts_with($expression, '{') && str_ends_with($expression, '}'))) {
            return true;
        }

        $startsWithValue = preg_match('/^(?:\$[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*|true\b|false\b|null\b|-?(?:\d+(?:\.\d+)?|\.\d+)|["\']|[\[\{\(])/i', $expression) === 1;
        if (!$startsWithValue) {
            return false;
        }

        return str_contains($expression, '|')
            || str_contains($expression, '~')
            || str_contains($expression, '??')
            || str_contains($expression, '?')
            || preg_match('/\s+(?:is(?:\s+not)?|in|not\s+in|matches|not\s+matches)\s+/i', $expression) === 1;
    }
    private static function isRegexLiteral(string $expression): bool
    {
        $length = strlen($expression);
        if ($length < 2 || $expression[0] !== '/') {
            return false;
        }

        $escaped = false;
        $inClass = false;
        for ($offset = 1; $offset < $length; ++$offset) {
            $char = $expression[$offset];
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
            if ($char !== '/' || $inClass) {
                continue;
            }

            for (++$offset; $offset < $length; ++$offset) {
                $modifier = $expression[$offset];
                if (!(($modifier >= 'a' && $modifier <= 'z') || ($modifier >= 'A' && $modifier <= 'Z'))) {
                    return false;
                }
            }
            return true;
        }

        return false;
    }

}
