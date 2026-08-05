<?php

declare(strict_types=1);

namespace XtScript\Regex;

use Throwable;
use XtScript\Exception\RegexException;

final class Regex
{
    public const MAX_RESULTS = 100_000;

    public static function test(string $pattern, string $subject, int $offset = 0): bool
    {
        try {
            $result = @preg_match($pattern, $subject, $matches, 0, $offset);
        } catch (Throwable $exception) {
            throw self::failure('match', $pattern, $exception);
        }
        if ($result === false) {
            throw self::failure('match', $pattern);
        }

        return $result === 1;
    }

    /** @return array<array-key, mixed>|null */
    public static function match(string $pattern, string $subject, int $offset = 0, bool $offsetCapture = false): ?array
    {
        $flags = PREG_UNMATCHED_AS_NULL | ($offsetCapture ? PREG_OFFSET_CAPTURE : 0);
        try {
            $result = @preg_match($pattern, $subject, $matches, $flags, $offset);
        } catch (Throwable $exception) {
            throw self::failure('match', $pattern, $exception);
        }
        if ($result === false) {
            throw self::failure('match', $pattern);
        }

        return $result === 1 ? $matches : null;
    }

    /** @return list<array<array-key, mixed>> */
    public static function matchAll(
        string $pattern,
        string $subject,
        int $offset = 0,
        int $limit = self::MAX_RESULTS,
        bool $offsetCapture = false,
    ): array {
        if ($limit < 0 || $limit > self::MAX_RESULTS) {
            throw new RegexException(sprintf('Regex match-all limit must be between 0 and %d.', self::MAX_RESULTS));
        }
        if ($limit === 0) {
            return [];
        }

        $flags = PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL | ($offsetCapture ? PREG_OFFSET_CAPTURE : 0);
        try {
            $result = @preg_match_all($pattern, $subject, $matches, $flags, $offset);
        } catch (Throwable $exception) {
            throw self::failure('match-all', $pattern, $exception);
        }
        if ($result === false) {
            throw self::failure('match-all', $pattern);
        }
        if ($result > $limit) {
            throw new RegexException(sprintf('Regex produced %d matches, exceeding the configured call limit of %d.', $result, $limit));
        }

        /** @var list<array<array-key, mixed>> $matches */
        return $matches;
    }

    public static function count(string $pattern, string $subject, int $offset = 0, int $limit = self::MAX_RESULTS): int
    {
        return count(self::matchAll($pattern, $subject, $offset, $limit));
    }

    /**
     * @param string|list<string> $pattern
     * @param string|list<string> $replacement
     * @param string|array<array-key, string> $subject
     * @return string|array<array-key, string>
     */
    public static function replace(
        string|array $pattern,
        string|array $replacement,
        string|array $subject,
        int $limit = -1,
    ): string|array {
        if ($limit < -1 || $limit > self::MAX_RESULTS) {
            throw new RegexException(sprintf('Regex replacement limit must be -1 or between 0 and %d.', self::MAX_RESULTS));
        }

        $count = 0;
        try {
            $result = @preg_replace($pattern, $replacement, $subject, $limit, $count);
        } catch (Throwable $exception) {
            throw self::failure('replace', self::patternDescription($pattern), $exception);
        }
        if ($result === null) {
            throw self::failure('replace', self::patternDescription($pattern));
        }
        if ($count > self::MAX_RESULTS) {
            throw new RegexException(sprintf('Regex replacement count %d exceeds the engine safety limit of %d.', $count, self::MAX_RESULTS));
        }

        return $result;
    }

    /** @return list<mixed> */
    public static function split(
        string $pattern,
        string $subject,
        int $limit = -1,
        int|string|array $flags = 0,
    ): array {
        if ($limit < -1 || $limit > self::MAX_RESULTS) {
            throw new RegexException(sprintf('Regex split limit must be -1 or between 0 and %d.', self::MAX_RESULTS));
        }

        try {
            $result = @preg_split($pattern, $subject, $limit, self::splitFlags($flags));
        } catch (Throwable $exception) {
            throw self::failure('split', $pattern, $exception);
        }
        if ($result === false) {
            throw self::failure('split', $pattern);
        }
        if (count($result) > self::MAX_RESULTS) {
            throw new RegexException(sprintf('Regex split produced more than %d values.', self::MAX_RESULTS));
        }

        return $result;
    }

    /**
     * @param array<array-key, mixed> $input
     * @return array<array-key, mixed>
     */
    public static function grep(string $pattern, array $input, bool $invert = false): array
    {
        if (count($input) > self::MAX_RESULTS) {
            throw new RegexException(sprintf('Regex grep input exceeds %d items.', self::MAX_RESULTS));
        }

        $flags = $invert ? PREG_GREP_INVERT : 0;
        $normalized = [];
        foreach ($input as $key => $value) {
            if (!is_scalar($value) && !$value instanceof \Stringable && $value !== null) {
                throw new RegexException(sprintf('Regex grep expects stringable values; key %s contains %s.', (string) $key, get_debug_type($value)));
            }
            $normalized[$key] = $value === null ? '' : (string) $value;
        }

        try {
            $result = @preg_grep($pattern, $normalized, $flags);
        } catch (Throwable $exception) {
            throw self::failure('grep', $pattern, $exception);
        }
        if ($result === false) {
            throw self::failure('grep', $pattern);
        }

        return $result;
    }

    public static function quote(string $literal, ?string $delimiter = null): string
    {
        if ($delimiter !== null && strlen($delimiter) !== 1) {
            throw new RegexException('Regex quote delimiter must be exactly one byte or null.');
        }

        return preg_quote($literal, $delimiter);
    }

    public static function valid(string $pattern): bool
    {
        try {
            return @preg_match($pattern, '') !== false;
        } catch (Throwable) {
            return false;
        }
    }

    private static function splitFlags(int|string|array $flags): int
    {
        if (is_int($flags)) {
            $allowed = PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_OFFSET_CAPTURE;
            if (($flags & ~$allowed) !== 0) {
                throw new RegexException('Regex split flags contain unsupported bits.');
            }
            return $flags;
        }

        $items = is_array($flags) ? $flags : (preg_split('/[\s,|]+/', trim($flags), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $value = 0;
        foreach ($items as $item) {
            if (is_int($item)) {
                $value |= self::splitFlags($item);
                continue;
            }
            $name = strtolower(trim((string) $item));
            if ($name === '' || $name === 'none') {
                continue;
            }
            $value |= match ($name) {
                'no_empty', 'no-empty' => PREG_SPLIT_NO_EMPTY,
                'delim_capture', 'delimiter_capture', 'delim-capture' => PREG_SPLIT_DELIM_CAPTURE,
                'offset_capture', 'offset-capture' => PREG_SPLIT_OFFSET_CAPTURE,
                default => throw new RegexException(sprintf('Unknown regex split flag "%s".', $name)),
            };
        }

        return $value;
    }

    private static function failure(string $operation, string $pattern, ?Throwable $exception = null): RegexException
    {
        $message = $exception?->getMessage()
            ?? (function_exists('preg_last_error_msg') ? preg_last_error_msg() : 'PCRE error ' . preg_last_error());
        $display = strlen($pattern) > 240 ? substr($pattern, 0, 237) . '...' : $pattern;
        return new RegexException(sprintf('Regex %s failed for pattern "%s": %s.', $operation, $display, $message));
    }

    /** @param string|array<array-key, string> $pattern */
    private static function patternDescription(string|array $pattern): string
    {
        return is_string($pattern) ? $pattern : '[multiple patterns]';
    }
}
