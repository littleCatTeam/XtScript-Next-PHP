<?php

declare(strict_types=1);

namespace XtScript\Runtime;

use InvalidArgumentException;
use Throwable;
use XtScript\Contract\SecurityPolicyInterface;
use XtScript\Exception\PluginException;
use XtScript\Exception\SecurityException;
use XtScript\Markup;
use XtScript\Plugin\FunctionContext;
use XtScript\Plugin\XtTagDefinition;

/**
 * Optional runtime renderer for plugin-provided prefixed tags.
 *
 * The default prefix is "xt", but EngineOptions::pluginTagPrefix may change it
 * to any validated prefix such as "cms" or "app". The core deliberately knows
 * nothing about the services behind those tags.
 */
final class XtTagRenderer
{
    private const NAME = '[A-Za-z][A-Za-z0-9_.-]*';
    private const ATTRS = '(?:\s+(?:"[^"]*"|\'[^\']*\'|[^"\'<>])*)?';

    private readonly string $prefix;
    private readonly string $prefixPattern;
    private readonly string $marker;

    /** @param array<string, XtTagDefinition> $definitions */
    public function __construct(
        private readonly array $definitions,
        private readonly ?XtTagDefinition $fallback = null,
        private readonly ?SecurityPolicyInterface $securityPolicy = null,
        string $prefix = 'xt',
    ) {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_.-]*$/D', $prefix) !== 1) {
            throw new InvalidArgumentException('Plugin tag prefix is invalid.');
        }
        $this->prefix = strtolower($prefix);
        $this->prefixPattern = preg_quote($this->prefix, '~');
        $this->marker = '<' . $this->prefix . ':';
    }

    public function render(string $output, FunctionContext $context, RuntimeState $state, int $maxOutputBytes): string
    {
        if (($this->definitions === [] && $this->fallback === null) || stripos($output, $this->marker) === false) {
            return $output;
        }

        $previous = null;
        while ($previous !== $output) {
            $previous = $output;
            $output = $this->replaceSelfClosing($output, $context, $state);

            // Resolve paired tags completely from the inside out before treating
            // a remaining opening tag as a historical HTML-style void call.
            $pairPrevious = null;
            while ($pairPrevious !== $output) {
                $pairPrevious = $output;
                $output = $this->replaceInnermostPairs($output, $context, $state);
            }

            $output = $this->replaceVoidOpening($output, $context, $state);
            if (strlen($output) > $maxOutputBytes) {
                throw new PluginException('XT tag expansion exceeded the output size limit.');
            }
        }

        return $output;
    }

    private function replaceSelfClosing(string $output, FunctionContext $context, RuntimeState $state): string
    {
        $pattern = '~<' . $this->prefixPattern . ':(' . self::NAME . ')(' . self::ATTRS . ')\s*/>~i';
        return preg_replace_callback($pattern, function (array $match) use ($context, $state): string {
            return $this->invoke(
                (string) $match[1],
                $this->parseAttributes((string) ($match[2] ?? '')),
                null,
                (string) $match[0],
                $context,
                $state,
            );
        }, $output) ?? $output;
    }

    private function replaceVoidOpening(string $output, FunctionContext $context, RuntimeState $state): string
    {
        // Historical XtGem code also used HTML-style void calls such as
        // <prefix:include file="/header"> without a trailing slash. Paired calls
        // have already been consumed above, so remaining openings are void calls.
        $pattern = '~<' . $this->prefixPattern . ':(' . self::NAME . ')(' . self::ATTRS . ')\s*>~i';
        return preg_replace_callback($pattern, function (array $match) use ($context, $state): string {
            $raw = (string) $match[0];
            if (str_ends_with(rtrim(substr($raw, 0, -1)), '/')) {
                return $raw;
            }
            return $this->invoke(
                (string) $match[1],
                $this->parseAttributes((string) ($match[2] ?? '')),
                null,
                $raw,
                $context,
                $state,
            );
        }, $output) ?? $output;
    }

    private function replaceInnermostPairs(string $output, FunctionContext $context, RuntimeState $state): string
    {
        // Match only an innermost pair. Repeated passes naturally resolve nested
        // tags without needing DOM/XML extensions (important for php -n support).
        $pattern = '~<' . $this->prefixPattern . ':(' . self::NAME . ')(' . self::ATTRS . ')\s*>(?:(?!<\/?' . $this->prefixPattern . ':).)*<\/' . $this->prefixPattern . ':\1\s*>~is';
        return preg_replace_callback($pattern, function (array $match) use ($context, $state): string {
            $opening = (string) $match[0];
            $name = (string) $match[1];
            $attrs = (string) ($match[2] ?? '');
            $openEnd = strpos($opening, '>');
            $closeStart = strripos($opening, '</' . $this->prefix . ':');
            if ($openEnd === false || $closeStart === false || $closeStart < $openEnd) {
                return $opening;
            }
            $body = substr($opening, $openEnd + 1, $closeStart - $openEnd - 1);

            return $this->invoke($name, $this->parseAttributes($attrs), $body, $opening, $context, $state);
        }, $output) ?? $output;
    }

    /** @param array<string, string> $attributes */
    private function invoke(
        string $name,
        array $attributes,
        ?string $body,
        string $raw,
        FunctionContext $context,
        RuntimeState $state,
    ): string {
        $normalized = strtolower($name);
        $definition = $this->definitions[$normalized] ?? $this->fallback;
        if ($definition === null) {
            return $raw;
        }

        if ($this->securityPolicy !== null && !$this->securityPolicy->allowsTag($this->prefix . ':' . $normalized)) {
            throw new SecurityException(sprintf('Plugin tag "%s:%s" is not allowed by the security policy in template "%s".', $this->prefix, $name, $context->template->name));
        }

        $state->tick();
        try {
            $value = ($definition->handler)($context, $normalized, $attributes, $body, $raw);
        } catch (SecurityException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new PluginException(sprintf('Plugin tag "%s:%s" failed: %s', $this->prefix, $name, $exception->getMessage()), 0, $exception);
        }

        if ($value === null) {
            return '';
        }
        if ($value instanceof Markup) {
            return $value->value;
        }
        if (is_scalar($value) || $value instanceof \Stringable) {
            return (string) $value;
        }

        throw new PluginException(sprintf('Plugin tag "%s:%s" returned a non-renderable value.', $this->prefix, $name));
    }

    /** @return array<string, string> */
    private function parseAttributes(string $source): array
    {
        $attributes = [];
        $offset = 0;
        $length = strlen($source);

        while ($offset < $length) {
            if (preg_match('/\G\s+/A', $source, $space, 0, $offset) === 1) {
                $offset += strlen((string) $space[0]);
                continue;
            }
            if (preg_match('/\G([A-Za-z_:][A-Za-z0-9_.:-]*)/A', $source, $nameMatch, 0, $offset) !== 1) {
                break;
            }
            $name = strtolower((string) $nameMatch[1]);
            $offset += strlen((string) $nameMatch[0]);

            if (preg_match('/\G\s*=\s*/A', $source, $equals, 0, $offset) !== 1) {
                $attributes[$name] = 'true';
                continue;
            }
            $offset += strlen((string) $equals[0]);

            if ($offset >= $length) {
                $attributes[$name] = '';
                break;
            }

            $quote = $source[$offset];
            if ($quote === '"' || $quote === "'") {
                ++$offset;
                $end = strpos($source, $quote, $offset);
                if ($end === false) {
                    $attributes[$name] = substr($source, $offset);
                    break;
                }
                $attributes[$name] = html_entity_decode(substr($source, $offset, $end - $offset), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $offset = $end + 1;
                continue;
            }

            if (preg_match('/\G([^\s<>]+)/A', $source, $valueMatch, 0, $offset) === 1) {
                $attributes[$name] = html_entity_decode((string) $valueMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $offset += strlen((string) $valueMatch[0]);
                continue;
            }

            $attributes[$name] = '';
        }

        return $attributes;
    }
}
