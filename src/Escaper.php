<?php

declare(strict_types=1);

namespace XtScript;

final class Escaper
{
    public static function escape(string $value, EscapeStrategy $strategy): string
    {
        return match ($strategy) {
            EscapeStrategy::None => $value,
            EscapeStrategy::Html => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'),
            EscapeStrategy::HtmlAttr => self::htmlAttribute($value),
            EscapeStrategy::Js => self::javascriptString($value),
            EscapeStrategy::Css => self::cssString($value),
            EscapeStrategy::Url => rawurlencode($value),
        };
    }

    private static function htmlAttribute(string $value): string
    {
        $escaped = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        $escaped = str_replace('`', '&#x60;', $escaped);
        return preg_replace_callback('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', static fn (array $match): string => sprintf('&#x%02X;', ord($match[0])), $escaped) ?? $escaped;
    }

    private static function javascriptString(string $value): string
    {
        $json = json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES,
        );
        return substr($json, 1, -1);
    }

    private static function cssString(string $value): string
    {
        return preg_replace_callback('/[\\"\'\n\r\f<>\x00-\x1F\x7F]/', static function (array $match): string {
            $byte = ord($match[0]);
            return '\\' . strtoupper(dechex($byte)) . ' ';
        }, $value) ?? $value;
    }
}
