<?php

declare(strict_types=1);

namespace XtScript;

enum EscapeStrategy: string
{
    case None = 'none';
    case Html = 'html';
    case HtmlAttr = 'html_attr';
    case Js = 'js';
    case Css = 'css';
    case Url = 'url';

    public static function fromTemplateValue(string $value): self
    {
        return match (strtolower(trim($value))) {
            'off', 'false', 'none' => self::None,
            'on', 'true', 'html' => self::Html,
            'html_attr', 'attr', 'attribute' => self::HtmlAttr,
            'js', 'javascript' => self::Js,
            'css' => self::Css,
            'url', 'uri' => self::Url,
            default => throw new \InvalidArgumentException(sprintf('Unknown escape strategy "%s".', $value)),
        };
    }
}
