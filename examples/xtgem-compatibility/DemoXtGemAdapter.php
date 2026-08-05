<?php

declare(strict_types=1);

namespace XtScript\Examples\XtGemCompatibility;

use XtScript\Markup;
use XtScript\Plugin\FunctionContext;

/**
 * Demonstration adapter, not an XtGem backend.
 *
 * It proves every XT name reaches application code and shows how a host can map
 * portable tags to request/context data. Real sites should replace this class.
 */
final class DemoXtGemAdapter implements XtGemAdapterInterface
{
    /** @param array<string, string> $attributes */
    public function render(
        string $name,
        array $attributes,
        ?string $body,
        string $raw,
        FunctionContext $context,
    ): mixed {
        return match ($name) {
            'url' => (string) $context->variable('request_url', '/'),
            'referer' => (string) $context->variable('request_referer', ''),
            'browser' => (string) $context->variable('request_browser', 'unknown'),
            'country' => (string) $context->variable('request_country', 'unknown'),
            'ip_address' => (string) $context->variable('request_ip', '0.0.0.0'),
            'get_device_template' => (string) $context->variable('device_template', 'default'),
            'gentime' => number_format($context->elapsedSeconds(), 6, '.', ''),
            'random' => (string) random_int(
                $this->intAttribute($attributes, 'from', $this->intAttribute($attributes, 'min', 0)),
                $this->intAttribute($attributes, 'to', $this->intAttribute($attributes, 'max', 100)),
            ),
            // Demonstrate paired XT tags without making them part of the core.
            'widget' => new Markup('<div data-demo-xt-widget="true">' . ($body ?? '') . '</div>'),
            default => $this->describe($name, $attributes, $body),
        };
    }

    /** @param array<string, string> $attributes */
    private function describe(string $name, array $attributes, ?string $body): Markup
    {
        $payload = htmlspecialchars(
            json_encode($attributes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
            'UTF-8',
        );
        $content = $body === null ? '' : htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');

        return new Markup(sprintf(
            '<span data-demo-xt="%s" data-attributes="%s">%s</span>',
            htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'),
            $payload,
            $content,
        ));
    }

    /** @param array<string, string> $attributes */
    private function intAttribute(array $attributes, string $name, int $default): int
    {
        $value = $attributes[$name] ?? null;
        if ($value === null || preg_match('/^-?\d+$/D', $value) !== 1) {
            return $default;
        }
        return (int) $value;
    }
}
