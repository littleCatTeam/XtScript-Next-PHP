<?php

declare(strict_types=1);

namespace XtScript\Formatter;

/**
 * Dependency-free, conservative formatters for template-generated assets.
 *
 * The JavaScript minifier intentionally preserves statement newlines to avoid
 * changing automatic-semicolon-insertion semantics. It is a safe size reducer,
 * not a replacement for an AST minifier such as Terser.
 */
final class CodeFormatter
{
    /** @var array<string, true> */
    private const HTML_VOID = [
        'area' => true, 'base' => true, 'br' => true, 'col' => true, 'embed' => true,
        'hr' => true, 'img' => true, 'input' => true, 'link' => true, 'meta' => true,
        'param' => true, 'source' => true, 'track' => true, 'wbr' => true,
    ];

    public static function beautifyHtml(string $html, string $indent = '  '): string
    {
        $protected = [];
        $html = preg_replace_callback(
            '~<(pre|textarea|script|style)\b([^>]*)>(.*?)</\1\s*>~is',
            static function (array $match) use (&$protected, $indent): string {
                $tag = strtolower($match[1]);
                $body = $match[3];
                if ($tag === 'script') {
                    $body = "\n" . self::indentLines(self::beautifyJs($body, $indent), 1, $indent) . "\n";
                } elseif ($tag === 'style') {
                    $body = "\n" . self::indentLines(self::beautifyCss($body, $indent), 1, $indent) . "\n";
                }
                $token = "\x1AXTHTML" . count($protected) . "\x1A";
                $protected[$token] = '<' . $match[1] . $match[2] . '>' . $body . '</' . $match[1] . '>';
                return $token;
            },
            $html,
        ) ?? $html;

        $tokens = preg_split('~(<!--.*?-->|<![^>]*>|<[^>]+>|\x1AXTHTML\d+\x1A)~s', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $depth = 0;
        $lines = [];
        foreach ($tokens as $token) {
            $trimmed = trim($token);
            if ($trimmed === '') {
                continue;
            }
            if (isset($protected[$trimmed])) {
                $lines[] = str_repeat($indent, $depth) . self::indentLines($protected[$trimmed], $depth, $indent, false);
                continue;
            }
            if ($trimmed[0] !== '<') {
                $text = preg_replace('/\s+/u', ' ', $trimmed) ?? $trimmed;
                if ($text !== '') {
                    $lines[] = str_repeat($indent, $depth) . $text;
                }
                continue;
            }

            if (preg_match('~^</\s*([A-Za-z][A-Za-z0-9:-]*)~', $trimmed, $close) === 1) {
                $depth = max(0, $depth - 1);
                $lines[] = str_repeat($indent, $depth) . $trimmed;
                continue;
            }

            $lines[] = str_repeat($indent, $depth) . $trimmed;
            if (str_starts_with($trimmed, '<!--') || str_starts_with($trimmed, '<!') || str_ends_with($trimmed, '/>')) {
                continue;
            }
            if (preg_match('~^<\s*([A-Za-z][A-Za-z0-9:-]*)\b~', $trimmed, $open) === 1) {
                $name = strtolower($open[1]);
                if (!isset(self::HTML_VOID[$name]) && !preg_match('~</\s*' . preg_quote($name, '~') . '\s*>$~i', $trimmed)) {
                    ++$depth;
                }
            }
        }

        return trim(implode("\n", $lines));
    }

    public static function beautifyCss(string $css, string $indent = '  '): string
    {
        $out = '';
        $depth = 0;
        $quote = null;
        $escaped = false;
        $inComment = false;
        $length = strlen($css);
        for ($i = 0; $i < $length; ++$i) {
            $c = $css[$i];
            $next = $i + 1 < $length ? $css[$i + 1] : '';
            if ($inComment) {
                $out .= $c;
                if ($c === '*' && $next === '/') {
                    $out .= '/';
                    ++$i;
                    $inComment = false;
                }
                continue;
            }
            if ($quote !== null) {
                $out .= $c;
                if ($escaped) {
                    $escaped = false;
                } elseif ($c === '\\') {
                    $escaped = true;
                } elseif ($c === $quote) {
                    $quote = null;
                }
                continue;
            }
            if (($c === '"' || $c === "'") ) {
                $quote = $c;
                $out .= $c;
                continue;
            }
            if ($c === '/' && $next === '*') {
                $out = rtrim($out) . ($out === '' ? '' : ' ') . '/*';
                ++$i;
                $inComment = true;
                continue;
            }
            if (self::isWhitespace($c)) {
                if ($out !== '' && !str_ends_with($out, "\n") && !str_ends_with($out, ' ')) {
                    $out .= ' ';
                }
                continue;
            }
            if ($c === '{') {
                $out = rtrim($out) . " {\n";
                ++$depth;
                $out .= str_repeat($indent, $depth);
                continue;
            }
            if ($c === '}') {
                $depth = max(0, $depth - 1);
                $out = rtrim($out) . "\n" . str_repeat($indent, $depth) . '}';
                if ($i + 1 < $length) {
                    $out .= "\n" . str_repeat($indent, $depth);
                }
                continue;
            }
            if ($c === ';') {
                $out = rtrim($out) . ";\n" . str_repeat($indent, $depth);
                continue;
            }
            $out .= $c;
        }
        return trim((string) preg_replace('/[ \t]+\n/', "\n", $out));
    }

    public static function minifyCss(string $css): string
    {
        $css = self::stripCssComments($css);
        $out = '';
        $pendingSpace = false;
        $quote = null;
        $escaped = false;
        $length = strlen($css);
        for ($i = 0; $i < $length; ++$i) {
            $c = $css[$i];
            if ($quote !== null) {
                $out .= $c;
                if ($escaped) {
                    $escaped = false;
                } elseif ($c === '\\') {
                    $escaped = true;
                } elseif ($c === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($c === '"' || $c === "'") {
                if ($pendingSpace && self::cssNeedsSpace($out, $c)) {
                    $out .= ' ';
                }
                $pendingSpace = false;
                $quote = $c;
                $out .= $c;
                continue;
            }
            if (self::isWhitespace($c)) {
                $pendingSpace = true;
                continue;
            }
            if ($pendingSpace && self::cssNeedsSpace($out, $c)) {
                $out .= ' ';
            }
            $pendingSpace = false;
            if (str_contains('{}:;,>+~()', $c)) {
                $out = rtrim($out);
            }
            $out .= $c;
        }
        return trim($out);
    }

    public static function beautifyJs(string $js, string $indent = '  '): string
    {
        $out = '';
        $depth = 0;
        $parenDepth = 0;
        $state = 'normal';
        $escaped = false;
        $regexClass = false;
        $length = strlen($js);
        for ($i = 0; $i < $length; ++$i) {
            $c = $js[$i];
            $next = $i + 1 < $length ? $js[$i + 1] : '';
            if ($state !== 'normal') {
                $out .= $c;
                if ($escaped) {
                    $escaped = false;
                    continue;
                }
                if ($c === '\\' && in_array($state, ['single', 'double', 'template', 'regex'], true)) {
                    $escaped = true;
                    continue;
                }
                if (($state === 'single' && $c === "'") || ($state === 'double' && $c === '"') || ($state === 'template' && $c === '`')) {
                    $state = 'normal';
                } elseif ($state === 'regex') {
                    if ($c === '[') $regexClass = true;
                    if ($c === ']') $regexClass = false;
                    if ($c === '/' && !$regexClass) $state = 'normal';
                } elseif ($state === 'linecomment' && ($c === "\n" || $c === "\r")) {
                    $state = 'normal';
                    $out .= str_repeat($indent, $depth);
                } elseif ($state === 'blockcomment' && $c === '*' && $next === '/') {
                    $out .= '/'; ++$i; $state = 'normal';
                }
                continue;
            }
            if ($c === '/' && $next === '/') { $out .= '//'; ++$i; $state = 'linecomment'; continue; }
            if ($c === '/' && $next === '*') { $out .= '/*'; ++$i; $state = 'blockcomment'; continue; }
            if ($c === "'") { $state = 'single'; $out .= $c; continue; }
            if ($c === '"') { $state = 'double'; $out .= $c; continue; }
            if ($c === '`') { $state = 'template'; $out .= $c; continue; }
            if ($c === '/' && self::looksLikeRegex($out)) { $state = 'regex'; $regexClass = false; $out .= $c; continue; }
            if ($c === '(' || $c === '[') { ++$parenDepth; $out .= $c; continue; }
            if ($c === ')' || $c === ']') { $parenDepth = max(0, $parenDepth - 1); $out .= $c; continue; }
            if ($c === '{') {
                $out = rtrim($out) . " {\n";
                ++$depth;
                $out .= str_repeat($indent, $depth);
                continue;
            }
            if ($c === '}') {
                $depth = max(0, $depth - 1);
                $out = rtrim($out) . "\n" . str_repeat($indent, $depth) . '}';
                if ($next !== ';' && $next !== ',' && $next !== ')' && $next !== ']') {
                    $out .= "\n" . str_repeat($indent, $depth);
                }
                continue;
            }
            if ($c === ';' && $parenDepth === 0) {
                $out = rtrim($out) . ";\n" . str_repeat($indent, $depth);
                continue;
            }
            if ($c === "\n" || $c === "\r") {
                if (!str_ends_with(rtrim($out), "\n")) {
                    $out = rtrim($out) . "\n" . str_repeat($indent, $depth);
                }
                continue;
            }
            $out .= $c;
        }
        return trim((string) preg_replace('/[ \t]+\n/', "\n", $out));
    }

    public static function minifyJs(string $js): string
    {
        $out = '';
        $state = 'normal';
        $escaped = false;
        $regexClass = false;
        $length = strlen($js);
        for ($i = 0; $i < $length; ++$i) {
            $c = $js[$i];
            $next = $i + 1 < $length ? $js[$i + 1] : '';
            if ($state === 'linecomment') {
                if ($c === "\n" || $c === "\r") {
                    $out = rtrim($out) . "\n";
                    $state = 'normal';
                }
                continue;
            }
            if ($state === 'blockcomment') {
                if ($c === '*' && $next === '/') {
                    ++$i; $state = 'normal';
                    if ($out !== '' && !self::isWhitespace(substr($out, -1))) $out .= ' ';
                }
                continue;
            }
            if ($state !== 'normal') {
                $out .= $c;
                if ($escaped) { $escaped = false; continue; }
                if ($c === '\\') { $escaped = true; continue; }
                if (($state === 'single' && $c === "'") || ($state === 'double' && $c === '"') || ($state === 'template' && $c === '`')) {
                    $state = 'normal';
                } elseif ($state === 'regex') {
                    if ($c === '[') $regexClass = true;
                    if ($c === ']') $regexClass = false;
                    if ($c === '/' && !$regexClass) $state = 'normal';
                }
                continue;
            }
            if ($c === '/' && $next === '/') { ++$i; $state = 'linecomment'; continue; }
            if ($c === '/' && $next === '*') { ++$i; $state = 'blockcomment'; continue; }
            if ($c === "'") { $state = 'single'; $out .= $c; continue; }
            if ($c === '"') { $state = 'double'; $out .= $c; continue; }
            if ($c === '`') { $state = 'template'; $out .= $c; continue; }
            if ($c === '/' && self::looksLikeRegex($out)) { $state = 'regex'; $regexClass = false; $out .= $c; continue; }
            $out .= $c;
        }

        $lines = preg_split('/\R/', $out) ?: [];
        $lines = array_map(static fn (string $line): string => trim((string) preg_replace('/[ \t]+/', ' ', $line)), $lines);
        $lines = array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));
        return implode("\n", $lines);
    }


    private static function isWhitespace(string $value): bool
    {
        return $value !== '' && str_contains(" \t\n\r\0\x0B\f", $value);
    }

    private static function stripCssComments(string $css): string
    {
        $out = '';
        $quote = null;
        $escaped = false;
        $length = strlen($css);
        for ($i = 0; $i < $length; ++$i) {
            $c = $css[$i];
            $next = $i + 1 < $length ? $css[$i + 1] : '';
            if ($quote !== null) {
                $out .= $c;
                if ($escaped) $escaped = false;
                elseif ($c === '\\') $escaped = true;
                elseif ($c === $quote) $quote = null;
                continue;
            }
            if ($c === '"' || $c === "'") { $quote = $c; $out .= $c; continue; }
            if ($c === '/' && $next === '*') {
                $i += 2;
                while ($i < $length && !($css[$i] === '*' && ($css[$i + 1] ?? '') === '/')) ++$i;
                ++$i;
                if ($out !== '' && !self::isWhitespace(substr($out, -1))) $out .= ' ';
                continue;
            }
            $out .= $c;
        }
        return $out;
    }

    private static function cssNeedsSpace(string $out, string $next): bool
    {
        if ($out === '') return false;
        $prev = substr($out, -1);
        if (str_contains('{}:;,>+~()', $prev) || str_contains('{}:;,>+~()', $next)) return false;
        return true;
    }

    private static function looksLikeRegex(string $out): bool
    {
        $trimmed = rtrim($out);
        if ($trimmed === '') return true;
        $last = substr($trimmed, -1);
        if (str_contains('([{:;,=!?&|+-*%^~<>', $last)) return true;
        if (preg_match('/(?:^|\W)(?:return|case|throw|typeof|instanceof|in|of|yield|await|void|delete|new)\s*$/', $trimmed) === 1) return true;
        return false;
    }

    private static function indentLines(string $value, int $depth, string $indent, bool $first = true): string
    {
        $prefix = str_repeat($indent, $depth);
        $lines = explode("\n", $value);
        foreach ($lines as $index => &$line) {
            if ($index === 0 && !$first) continue;
            $line = $prefix . $line;
        }
        unset($line);
        return implode("\n", $lines);
    }
}
