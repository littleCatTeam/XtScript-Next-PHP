<?php

declare(strict_types=1);

namespace XtScript\Plugin;

use Countable;
use Traversable;
use XtScript\Context;
use XtScript\EscapeStrategy;
use XtScript\Escaper;
use XtScript\Formatter\CodeFormatter;
use XtScript\Markup;
use XtScript\Regex\Regex;
use XtScript\Contract\PluginInterface;

final class CorePlugin implements PluginInterface
{
    use PluginTrait;

    private const MAX_COLLECTION_ITEMS = 100_000;

    public function getName(): string
    {
        return 'core';
    }

    public function getFunctions(): iterable
    {
        foreach ($this->unaryStringFunctions() as $name => $callable) {
            yield new FunctionDefinition($name, static fn (FunctionContext $context, array $args): mixed => $callable((string) self::arg($args, '$val', '')));
        }

        yield new FunctionDefinition('chr', static fn (FunctionContext $context, array $args): string => chr((int) self::arg($args, '$val', 0) & 0xff));
        yield new FunctionDefinition('ord', static function (FunctionContext $context, array $args): int|string {
            $value = (string) self::arg($args, '$val', '');
            return $value === '' ? '' : ord($value[0]);
        });
        yield new FunctionDefinition('base64_decode', static function (FunctionContext $context, array $args): string {
            $decoded = base64_decode((string) self::arg($args, '$val', ''), true);
            return $decoded === false ? '' : $decoded;
        });
        yield new FunctionDefinition('hex2bin', static function (FunctionContext $context, array $args): string {
            $value = (string) self::arg($args, '$val', '');
            if ($value === '' || strlen($value) % 2 !== 0 || preg_match('/^[0-9a-f]+$/iD', $value) !== 1) {
                return '';
            }
            return hex2bin($value) ?: '';
        });
        yield new FunctionDefinition('hexdec', static fn (FunctionContext $context, array $args): int|float => hexdec((string) self::arg($args, '$val', '0')));
        yield new FunctionDefinition('dechex', static fn (FunctionContext $context, array $args): string => dechex(max(0, (int) self::arg($args, '$val', 0))));
        yield new FunctionDefinition('br2nl', static fn (FunctionContext $context, array $args): string => str_ireplace(['<br>', '<br/>', '<br />'], "\n", (string) self::arg($args, '$val', '')));
        yield new FunctionDefinition('str_replace', static fn (FunctionContext $context, array $args): string => str_replace(
            (string) self::arg($args, '$search', ''),
            (string) self::arg($args, '$replace', ''),
            (string) self::arg($args, '$subject', ''),
        ));
        yield new FunctionDefinition('str_ireplace', static fn (FunctionContext $context, array $args): string => str_ireplace(
            (string) self::arg($args, '$search', ''),
            (string) self::arg($args, '$replace', ''),
            (string) self::arg($args, '$subject', ''),
        ));
        yield new FunctionDefinition('substr', static function (FunctionContext $context, array $args): string {
            $value = (string) self::arg($args, '$val', '');
            $start = (int) self::arg($args, '$start', 0);
            $length = array_key_exists('$length', $args) ? (int) $args['$length'] : null;
            return $length === null ? substr($value, $start) : substr($value, $start, $length);
        });
        yield new FunctionDefinition('str_repeat', static function (FunctionContext $context, array $args): string {
            $value = (string) self::arg($args, '$val', '');
            $multiplier = max(0, min(self::MAX_COLLECTION_ITEMS, (int) self::arg($args, '$multiplier', 0)));
            if (strlen($value) * $multiplier > 1_048_576) {
                throw new \LengthException('str_repeat result exceeds 1 MiB.');
            }
            return str_repeat($value, $multiplier);
        });
        yield new FunctionDefinition('str_pad', static function (FunctionContext $context, array $args): string {
            $value = (string) self::arg($args, '$val', '');
            $length = max(0, min(1_048_576, (int) self::arg($args, '$pad_length', strlen($value))));
            $pad = (string) self::arg($args, '$pad_string', ' ');
            if ($pad === '') {
                $pad = ' ';
            }
            $type = match (strtoupper((string) self::arg($args, '$pad_type', 'STR_PAD_RIGHT'))) {
                'STR_PAD_LEFT' => STR_PAD_LEFT,
                'STR_PAD_BOTH' => STR_PAD_BOTH,
                default => STR_PAD_RIGHT,
            };
            return str_pad($value, $length, $pad, $type);
        });
        yield new FunctionDefinition('strip_tags', static fn (FunctionContext $context, array $args): string => strip_tags(
            (string) self::arg($args, '$val', ''),
            (string) self::arg($args, '$allowable_tags', ''),
        ));

        foreach (['strpos', 'strrpos', 'stripos', 'strripos'] as $name) {
            yield new FunctionDefinition($name, static function (FunctionContext $context, array $args) use ($name): int|false {
                $haystack = (string) self::arg($args, '$haystack', '');
                $needle = (string) self::arg($args, '$needle', '');
                $offset = (int) self::arg($args, '$offset', 0);
                if ($offset > strlen($haystack) || $offset < -strlen($haystack)) {
                    $offset = 0;
                }
                return $name($haystack, $needle, $offset);
            });
        }

        foreach (['strstr', 'stristr'] as $name) {
            yield new FunctionDefinition($name, static fn (FunctionContext $context, array $args): string|false => $name(
                (string) self::arg($args, '$haystack', ''),
                (string) self::arg($args, '$needle', ''),
                self::truthy(self::arg($args, '$before_needle', false)),
            ));
        }
        yield new FunctionDefinition('strrchr', static fn (FunctionContext $context, array $args): string|false => strrchr(
            (string) self::arg($args, '$haystack', ''),
            (string) self::arg($args, '$needle', ''),
        ));

        yield new FunctionDefinition('abs', static fn (FunctionContext $context, array $args): int|float => abs((float) self::arg($args, '$num', 0)));
        yield new FunctionDefinition('ceil', static fn (FunctionContext $context, array $args): float => ceil((float) self::arg($args, '$num', 0)));
        yield new FunctionDefinition('floor', static fn (FunctionContext $context, array $args): float => floor((float) self::arg($args, '$num', 0)));
        yield new FunctionDefinition('round', static fn (FunctionContext $context, array $args): float => round(
            (float) self::arg($args, '$num', 0),
            (int) self::arg($args, '$precision', 0),
        ));
        yield new FunctionDefinition('pow', static fn (FunctionContext $context, array $args): int|float => pow(
            (float) self::arg($args, '$num', 0),
            (float) self::arg($args, '$exp', 0),
        ));
        yield new FunctionDefinition('sqrt', static function (FunctionContext $context, array $args): float {
            $number = (float) self::arg($args, '$num', 0);
            if ($number < 0) {
                throw new \DomainException('sqrt requires a non-negative number.');
            }
            return sqrt($number);
        });
        yield new FunctionDefinition('pi', static fn (FunctionContext $context, array $args): float => M_PI);
        yield new FunctionDefinition('mt_rand', static function (FunctionContext $context, array $args): int {
            if (!array_key_exists('$min', $args) && !array_key_exists('$max', $args)) {
                return mt_rand();
            }
            $min = (int) self::arg($args, '$min', 0);
            $max = (int) self::arg($args, '$max', mt_getrandmax());
            return $max >= $min ? mt_rand($min, $max) : $min;
        });

        // Modern collection/data/system functions. Legacy names above remain intact.
        yield new FunctionDefinition('range', static function (FunctionContext $context, array $args): array {
            return self::makeRange(
                (int) self::arg($args, '$start', 0),
                (int) self::arg($args, '$end', 0),
                (int) self::arg($args, '$step', 1),
            );
        });
        yield new FunctionDefinition('min', static fn (FunctionContext $context, array $args): mixed => self::extreme(self::collectionFromArgs($args), true));
        yield new FunctionDefinition('max', static fn (FunctionContext $context, array $args): mixed => self::extreme(self::collectionFromArgs($args), false));
        yield new FunctionDefinition('first', static fn (FunctionContext $context, array $args): mixed => self::first(self::arg($args, '$val', self::arg($args, '$values', ''))));
        yield new FunctionDefinition('last', static fn (FunctionContext $context, array $args): mixed => self::last(self::arg($args, '$val', self::arg($args, '$values', ''))));
        yield new FunctionDefinition('keys', static fn (FunctionContext $context, array $args): array => array_keys(self::toArray(self::arg($args, '$val', self::arg($args, '$values', [])))));
        yield new FunctionDefinition('join', static fn (FunctionContext $context, array $args): string => implode(
            (string) self::arg($args, '$glue', self::arg($args, '$separator', '')),
            array_map(self::stringify(...), array_values(self::toArray(self::arg($args, '$val', self::arg($args, '$values', []))))),
        ));
        yield new FunctionDefinition('split', static fn (FunctionContext $context, array $args): array => self::splitString(
            (string) self::arg($args, '$val', ''),
            (string) self::arg($args, '$delimiter', ''),
            (int) self::arg($args, '$limit', PHP_INT_MAX),
        ));
        yield new FunctionDefinition('sort', static fn (FunctionContext $context, array $args): array => self::sorted(self::toArray(self::arg($args, '$val', self::arg($args, '$values', [])))));
        yield new FunctionDefinition('shuffle', static fn (FunctionContext $context, array $args): array => self::shuffled(self::toArray(self::arg($args, '$val', self::arg($args, '$values', [])))));
        yield new FunctionDefinition('merge', static fn (FunctionContext $context, array $args): array => array_merge(
            self::toArray(self::arg($args, '$left', self::arg($args, '$a', []))),
            self::toArray(self::arg($args, '$right', self::arg($args, '$b', []))),
        ));
        yield new FunctionDefinition('json_encode', static fn (FunctionContext $context, array $args): string => json_encode(
            self::arg($args, '$val'),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            64,
        ));
        yield new FunctionDefinition('json_decode', static fn (FunctionContext $context, array $args): mixed => json_decode(
            (string) self::arg($args, '$val', ''),
            true,
            64,
            JSON_THROW_ON_ERROR,
        ));
        yield new FunctionDefinition('starts_with', static fn (FunctionContext $context, array $args): bool => str_starts_with(
            (string) self::arg($args, '$val', self::arg($args, '$haystack', '')),
            (string) self::arg($args, '$prefix', self::arg($args, '$needle', '')),
        ));
        yield new FunctionDefinition('ends_with', static fn (FunctionContext $context, array $args): bool => str_ends_with(
            (string) self::arg($args, '$val', self::arg($args, '$haystack', '')),
            (string) self::arg($args, '$suffix', self::arg($args, '$needle', '')),
        ));
        yield new FunctionDefinition('regex_test', static fn (FunctionContext $context, array $args): bool => Regex::test(
            (string) self::arg($args, '$pattern', ''),
            (string) self::arg($args, '$subject', self::arg($args, '$val', '')),
            (int) self::arg($args, '$offset', 0),
        ));
        yield new FunctionDefinition('regex_match', static fn (FunctionContext $context, array $args): ?array => Regex::match(
            (string) self::arg($args, '$pattern', ''),
            (string) self::arg($args, '$subject', self::arg($args, '$val', '')),
            (int) self::arg($args, '$offset', 0),
            (bool) self::arg($args, '$offset_capture', false),
        ));
        yield new FunctionDefinition('regex_match_all', static fn (FunctionContext $context, array $args): array => Regex::matchAll(
            (string) self::arg($args, '$pattern', ''),
            (string) self::arg($args, '$subject', self::arg($args, '$val', '')),
            (int) self::arg($args, '$offset', 0),
            (int) self::arg($args, '$limit', Regex::MAX_RESULTS),
            (bool) self::arg($args, '$offset_capture', false),
        ));
        yield new FunctionDefinition('regex_count', static fn (FunctionContext $context, array $args): int => Regex::count(
            (string) self::arg($args, '$pattern', ''),
            (string) self::arg($args, '$subject', self::arg($args, '$val', '')),
            (int) self::arg($args, '$offset', 0),
            (int) self::arg($args, '$limit', Regex::MAX_RESULTS),
        ));
        yield new FunctionDefinition('regex_replace', static fn (FunctionContext $context, array $args): string|array => Regex::replace(
            self::regexStringOrList(self::arg($args, '$pattern', '')),
            self::regexStringOrList(self::arg($args, '$replacement', '')),
            self::regexSubject(self::arg($args, '$subject', self::arg($args, '$val', ''))),
            (int) self::arg($args, '$limit', -1),
        ));
        yield new FunctionDefinition('regex_split', static fn (FunctionContext $context, array $args): array => Regex::split(
            (string) self::arg($args, '$pattern', ''),
            (string) self::arg($args, '$subject', self::arg($args, '$val', '')),
            (int) self::arg($args, '$limit', -1),
            self::regexSplitFlags(self::arg($args, '$flags', 0)),
        ));
        yield new FunctionDefinition('regex_grep', static fn (FunctionContext $context, array $args): array => Regex::grep(
            (string) self::arg($args, '$pattern', ''),
            self::toArray(self::arg($args, '$values', self::arg($args, '$val', []))),
            (bool) self::arg($args, '$invert', false),
        ));
        yield new FunctionDefinition('regex_quote', static fn (FunctionContext $context, array $args): string => Regex::quote(
            (string) self::arg($args, '$val', self::arg($args, '$literal', '')),
            array_key_exists('$delimiter', $args) && $args['$delimiter'] !== null ? (string) $args['$delimiter'] : null,
        ));
        yield new FunctionDefinition('regex_valid', static fn (FunctionContext $context, array $args): bool => Regex::valid(
            (string) self::arg($args, '$pattern', self::arg($args, '$val', '')),
        ));

        yield new FunctionDefinition('date', static fn (FunctionContext $context, array $args): string => date(
            (string) self::arg($args, '$format', 'Y-m-d H:i:s'),
            array_key_exists('$timestamp', $args) ? (int) $args['$timestamp'] : time(),
        ));
        yield new FunctionDefinition('random', static fn (FunctionContext $context, array $args): mixed => self::randomValue($args));
        yield new FunctionDefinition('cycle', static fn (FunctionContext $context, array $args): mixed => self::cycle(
            self::toArray(self::arg($args, '$values', self::arg($args, '$val', []))),
            (int) self::arg($args, '$index', 0),
        ));

        yield new FunctionDefinition('execution_time', static fn (FunctionContext $context, array $args): string => number_format($context->elapsedSeconds(), 6, '.', '') . 's.');
        yield new FunctionDefinition('template_name', static fn (FunctionContext $context, array $args): string => $context->template->name);
        yield new FunctionDefinition('get_variable', static function (FunctionContext $context, array $args): mixed {
            $name = (string) self::arg($args, '$name', '');
            return $name === '' ? '' : $context->variable($name, '');
        });
        yield new FunctionDefinition('source', static function (FunctionContext $context, array $args): string {
            $name = (string) self::arg($args, '$file', self::arg($args, '$name', ''));
            return $context->load($name)->code;
        });
        yield new FunctionDefinition('file_get_contents', static function (FunctionContext $context, array $args): string {
            $name = (string) self::arg($args, '$file', self::arg($args, '$name', ''));
            return $context->load($name)->code;
        });
    }

    public function getTags(): iterable
    {
        foreach ([
            'assign', 'var', 'get', 'get_or_default', 'delete', 'del', 'print', 'print_raw', 'return', 'call', 'include',
            'extends', 'block', 'endblock', 'section', 'endsection', 'yield', 'parent',
            'if', 'elseif', 'else', 'endif',
            'foreach', 'endforeach', 'for', 'endfor', 'break', 'continue',
            'switch', 'case', 'default', 'endswitch',
            'component', 'slot', 'endslot', 'endcomponent', 'capture', 'endcapture', 'cache', 'endcache',
            'with', 'endwith', 'do', 'once', 'endonce', 'apply', 'endapply', 'autoescape', 'endautoescape',
            'beautify', 'endbeautify', 'minify', 'endminify', 'import',
            'verbatim', 'endverbatim', 'push', 'endpush', 'prepend', 'endprepend', 'stack',
            'function', 'endfunction', 'goto',
        ] as $tag) {
            yield new TagDefinition($tag);
        }
    }

    public function getFilters(): iterable
    {
        yield new FilterDefinition('raw', static fn (Context $context, mixed $value, array $args, bool $defined): Markup => new Markup(self::stringify($value)));
        yield new FilterDefinition('escape', static fn (Context $context, mixed $value, array $args, bool $defined): Markup => new Markup(Escaper::escape(self::stringify($value), EscapeStrategy::fromTemplateValue((string) ($args[0] ?? 'html')))));
        yield new FilterDefinition('e', static fn (Context $context, mixed $value, array $args, bool $defined): Markup => new Markup(Escaper::escape(self::stringify($value), EscapeStrategy::fromTemplateValue((string) ($args[0] ?? 'html')))));
        yield new FilterDefinition('upper', static fn (Context $context, mixed $value, array $args, bool $defined): string => strtoupper(self::stringify($value)));
        yield new FilterDefinition('lower', static fn (Context $context, mixed $value, array $args, bool $defined): string => strtolower(self::stringify($value)));
        yield new FilterDefinition('trim', static fn (Context $context, mixed $value, array $args, bool $defined): string => trim(self::stringify($value), isset($args[0]) ? (string) $args[0] : " \t\n\r\0\x0B"));
        yield new FilterDefinition('capitalize', static fn (Context $context, mixed $value, array $args, bool $defined): string => ucfirst(strtolower(self::stringify($value))));
        yield new FilterDefinition('title', static fn (Context $context, mixed $value, array $args, bool $defined): string => ucwords(strtolower(self::stringify($value))));
        yield new FilterDefinition('length', static fn (Context $context, mixed $value, array $args, bool $defined): int => self::length($value));
        yield new FilterDefinition('join', static fn (Context $context, mixed $value, array $args, bool $defined): string => implode(
            isset($args[0]) ? (string) $args[0] : '',
            array_map(self::stringify(...), array_values(self::toArray($value))),
        ));
        yield new FilterDefinition('split', static fn (Context $context, mixed $value, array $args, bool $defined): array => self::splitString(
            self::stringify($value),
            isset($args[0]) ? (string) $args[0] : '',
            isset($args[1]) ? (int) $args[1] : PHP_INT_MAX,
        ));
        yield new FilterDefinition('slice', static fn (Context $context, mixed $value, array $args, bool $defined): mixed => self::slice(
            $value,
            isset($args[0]) ? (int) $args[0] : 0,
            array_key_exists(1, $args) ? (int) $args[1] : null,
        ));
        yield new FilterDefinition('reverse', static fn (Context $context, mixed $value, array $args, bool $defined): mixed => self::reverse($value));
        yield new FilterDefinition('sort', static fn (Context $context, mixed $value, array $args, bool $defined): array => self::sorted(self::toArray($value)));
        yield new FilterDefinition('shuffle', static fn (Context $context, mixed $value, array $args, bool $defined): array => self::shuffled(self::toArray($value)));
        yield new FilterDefinition('keys', static fn (Context $context, mixed $value, array $args, bool $defined): array => array_keys(self::toArray($value)));
        yield new FilterDefinition('first', static fn (Context $context, mixed $value, array $args, bool $defined): mixed => self::first($value));
        yield new FilterDefinition('last', static fn (Context $context, mixed $value, array $args, bool $defined): mixed => self::last($value));
        yield new FilterDefinition('default', static fn (Context $context, mixed $value, array $args, bool $defined): mixed => !$defined || self::isEmpty($value) ? ($args[0] ?? '') : $value);
        yield new FilterDefinition('replace', static fn (Context $context, mixed $value, array $args, bool $defined): string => str_replace(
            isset($args[0]) ? (string) $args[0] : '',
            isset($args[1]) ? (string) $args[1] : '',
            self::stringify($value),
        ));
        yield new FilterDefinition('json_encode', static fn (Context $context, mixed $value, array $args, bool $defined): string => json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            64,
        ));
        yield new FilterDefinition('url_encode', static fn (Context $context, mixed $value, array $args, bool $defined): string => rawurlencode(self::stringify($value)));
        yield new FilterDefinition('matches', static fn (Context $context, mixed $value, array $args, bool $defined): bool => $defined
            && isset($args[0])
            && Regex::test((string) $args[0], self::stringify($value), isset($args[1]) ? (int) $args[1] : 0));
        yield new FilterDefinition('regex_match', static fn (Context $context, mixed $value, array $args, bool $defined): ?array => !$defined || !isset($args[0])
            ? null
            : Regex::match((string) $args[0], self::stringify($value), isset($args[1]) ? (int) $args[1] : 0, isset($args[2]) && (bool) $args[2]));
        yield new FilterDefinition('regex_match_all', static fn (Context $context, mixed $value, array $args, bool $defined): array => !$defined || !isset($args[0])
            ? []
            : Regex::matchAll((string) $args[0], self::stringify($value), isset($args[1]) ? (int) $args[1] : 0, isset($args[2]) ? (int) $args[2] : Regex::MAX_RESULTS, isset($args[3]) && (bool) $args[3]));
        yield new FilterDefinition('regex_count', static fn (Context $context, mixed $value, array $args, bool $defined): int => !$defined || !isset($args[0])
            ? 0
            : Regex::count((string) $args[0], self::stringify($value), isset($args[1]) ? (int) $args[1] : 0, isset($args[2]) ? (int) $args[2] : Regex::MAX_RESULTS));
        yield new FilterDefinition('regex_replace', static fn (Context $context, mixed $value, array $args, bool $defined): string|array => !$defined || !isset($args[0])
            ? self::stringify($value)
            : Regex::replace(self::regexStringOrList($args[0]), self::regexStringOrList($args[1] ?? ''), self::regexSubject($value), isset($args[2]) ? (int) $args[2] : -1));
        yield new FilterDefinition('regex_split', static fn (Context $context, mixed $value, array $args, bool $defined): array => !$defined || !isset($args[0])
            ? []
            : Regex::split((string) $args[0], self::stringify($value), isset($args[1]) ? (int) $args[1] : -1, self::regexSplitFlags($args[2] ?? 0)));
        yield new FilterDefinition('regex_grep', static fn (Context $context, mixed $value, array $args, bool $defined): array => !$defined || !isset($args[0])
            ? []
            : Regex::grep((string) $args[0], self::toArray($value), isset($args[1]) && (bool) $args[1]));
        yield new FilterDefinition('regex_quote', static fn (Context $context, mixed $value, array $args, bool $defined): string => Regex::quote(
            self::stringify($value),
            isset($args[0]) && $args[0] !== null ? (string) $args[0] : null,
        ));

        yield new FilterDefinition('date', static fn (Context $context, mixed $value, array $args, bool $defined): string => date(
            isset($args[0]) ? (string) $args[0] : 'Y-m-d H:i:s',
            $defined && $value !== '' && $value !== null ? (int) $value : time(),
        ));
        yield new FilterDefinition('beautify_html', static fn (Context $context, mixed $value, array $args, bool $defined): string => CodeFormatter::beautifyHtml(self::stringify($value), isset($args[0]) ? (string) $args[0] : '  '));
        yield new FilterDefinition('beautify_css', static fn (Context $context, mixed $value, array $args, bool $defined): string => CodeFormatter::beautifyCss(self::stringify($value), isset($args[0]) ? (string) $args[0] : '  '));
        yield new FilterDefinition('beautify_js', static fn (Context $context, mixed $value, array $args, bool $defined): string => CodeFormatter::beautifyJs(self::stringify($value), isset($args[0]) ? (string) $args[0] : '  '));
        yield new FilterDefinition('minify_css', static fn (Context $context, mixed $value, array $args, bool $defined): string => CodeFormatter::minifyCss(self::stringify($value)));
        yield new FilterDefinition('minify_js', static fn (Context $context, mixed $value, array $args, bool $defined): string => CodeFormatter::minifyJs(self::stringify($value)));
    }

    public function getTests(): iterable
    {
        yield new TestDefinition('matches', static fn (Context $context, mixed $value, array $args, bool $defined): bool => $defined
            && isset($args[0])
            && Regex::test((string) $args[0], self::stringify($value), isset($args[1]) ? (int) $args[1] : 0));
        yield new TestDefinition('regex', static fn (Context $context, mixed $value, array $args, bool $defined): bool => $defined && Regex::valid(self::stringify($value)));
        yield new TestDefinition('defined', static fn (Context $context, mixed $value, array $args, bool $defined): bool => $defined);
        yield new TestDefinition('empty', static fn (Context $context, mixed $value, array $args, bool $defined): bool => !$defined || self::isEmpty($value));
        yield new TestDefinition('null', static fn (Context $context, mixed $value, array $args, bool $defined): bool => $defined && $value === null);
        yield new TestDefinition('iterable', static fn (Context $context, mixed $value, array $args, bool $defined): bool => $defined && is_iterable($value));
        yield new TestDefinition('mapping', static fn (Context $context, mixed $value, array $args, bool $defined): bool => $defined && is_array($value));
        yield new TestDefinition('sequence', static fn (Context $context, mixed $value, array $args, bool $defined): bool => $defined && ((is_array($value) && array_is_list($value)) || $value instanceof Traversable));
        yield new TestDefinition('even', static fn (Context $context, mixed $value, array $args, bool $defined): bool => $defined && self::isNumber($value) && ((int) $value % 2 === 0));
        yield new TestDefinition('odd', static fn (Context $context, mixed $value, array $args, bool $defined): bool => $defined && self::isNumber($value) && ((int) $value % 2 !== 0));
        yield new TestDefinition('divisible_by', static fn (Context $context, mixed $value, array $args, bool $defined): bool => $defined
            && self::isNumber($value)
            && isset($args[0])
            && self::isNumber($args[0])
            && (float) $args[0] != 0.0
            && ((int) $value % (int) $args[0] === 0));
        yield new TestDefinition('same_as', static fn (Context $context, mixed $value, array $args, bool $defined): bool => $defined && array_key_exists(0, $args) && $value === $args[0]);
        yield new TestDefinition('string', static fn (Context $context, mixed $value, array $args, bool $defined): bool => $defined && is_string($value));
        yield new TestDefinition('number', static fn (Context $context, mixed $value, array $args, bool $defined): bool => $defined && self::isNumber($value));
        yield new TestDefinition('array', static fn (Context $context, mixed $value, array $args, bool $defined): bool => $defined && is_array($value));
        yield new TestDefinition('boolean', static fn (Context $context, mixed $value, array $args, bool $defined): bool => $defined && is_bool($value));
    }

    public function getGlobals(): array
    {
        return [];
    }

    /** @return string|list<string> */
    private static function regexStringOrList(mixed $value): string|array
    {
        if (is_array($value) || $value instanceof Traversable) {
            $result = [];
            foreach (self::toArray($value) as $item) {
                $result[] = self::stringify($item);
            }
            return $result;
        }
        return self::stringify($value);
    }

    /** @return string|array<array-key, string> */
    private static function regexSubject(mixed $value): string|array
    {
        if (is_array($value) || $value instanceof Traversable) {
            $result = [];
            foreach (self::toArray($value) as $key => $item) {
                $result[$key] = self::stringify($item);
            }
            return $result;
        }
        return self::stringify($value);
    }

    /** @return int|string|array<array-key, mixed> */
    private static function regexSplitFlags(mixed $value): int|string|array
    {
        if (is_int($value) || is_string($value) || is_array($value)) {
            return $value;
        }
        if ($value instanceof Traversable) {
            return self::toArray($value);
        }
        throw new \InvalidArgumentException('Regex split flags must be an integer, string, or array.');
    }

    /** @return array<string, callable(string): mixed> */
    private function unaryStringFunctions(): array
    {
        return [
            'urlencode' => urlencode(...),
            'urldecode' => urldecode(...),
            'rawurlencode' => rawurlencode(...),
            'rawurldecode' => rawurldecode(...),
            'crc32' => crc32(...),
            'md5' => md5(...),
            'sha1' => sha1(...),
            'base64_encode' => base64_encode(...),
            'bin2hex' => bin2hex(...),
            'htmlspecialchars' => static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'),
            'lcfirst' => lcfirst(...),
            'ucfirst' => ucfirst(...),
            'ucwords' => ucwords(...),
            'strtoupper' => strtoupper(...),
            'strtolower' => strtolower(...),
            'trim' => trim(...),
            'ltrim' => ltrim(...),
            'rtrim' => rtrim(...),
            'nl2br' => nl2br(...),
            'str_shuffle' => str_shuffle(...),
            'addslashes' => addslashes(...),
            'stripslashes' => stripslashes(...),
            'strrev' => strrev(...),
            'strlen' => strlen(...),
        ];
    }

    /** @param array<string, mixed> $args */
    private static function arg(array $args, string $name, mixed $default = null): mixed
    {
        return $args[$name] ?? $default;
    }

    /** @param array<string, mixed> $args @return list<mixed> */
    private static function collectionFromArgs(array $args): array
    {
        if (array_key_exists('$values', $args)) {
            return array_values(self::toArray($args['$values']));
        }
        if (array_key_exists('$val', $args)) {
            return array_values(self::toArray($args['$val']));
        }
        return array_values($args);
    }

    /** @return array<array-key, mixed> */
    private static function toArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if ($value instanceof Traversable) {
            $result = [];
            foreach ($value as $key => $item) {
                if (count($result) >= self::MAX_COLLECTION_ITEMS) {
                    throw new \LengthException('Iterable exceeds the collection item limit.');
                }
                $result[$key] = $item;
            }
            return $result;
        }
        throw new \InvalidArgumentException(sprintf('Expected iterable value, got %s.', get_debug_type($value)));
    }

    /** @return list<int> */
    private static function makeRange(int $start, int $end, int $step): array
    {
        if ($step === 0) {
            throw new \InvalidArgumentException('range step cannot be zero.');
        }
        $step = abs($step);
        $distance = abs($end - $start);
        $count = intdiv($distance, $step) + 1;
        if ($count > self::MAX_COLLECTION_ITEMS) {
            throw new \LengthException('range exceeds the collection item limit.');
        }
        $signedStep = $end >= $start ? $step : -$step;
        /** @var list<int> $range */
        $range = range($start, $end, $signedStep);
        return $range;
    }

    /** @param list<mixed> $values */
    private static function extreme(array $values, bool $minimum): mixed
    {
        if ($values === []) {
            return '';
        }
        return $minimum ? min($values) : max($values);
    }

    private static function first(mixed $value): mixed
    {
        if (is_string($value)) {
            return $value === '' ? '' : $value[0];
        }
        $values = array_values(self::toArray($value));
        return $values[0] ?? '';
    }

    private static function last(mixed $value): mixed
    {
        if (is_string($value)) {
            return $value === '' ? '' : $value[strlen($value) - 1];
        }
        $values = array_values(self::toArray($value));
        return $values === [] ? '' : $values[array_key_last($values)];
    }

    private static function length(mixed $value): int
    {
        return match (true) {
            is_string($value) => strlen($value),
            is_array($value) => count($value),
            $value instanceof Countable => count($value),
            $value instanceof Traversable => self::traversableLength($value),
            $value === null => 0,
            default => strlen(self::stringify($value)),
        };
    }

    /** @return list<string> */
    private static function splitString(string $value, string $delimiter, int $limit): array
    {
        if ($value === '') {
            return [];
        }
        if ($delimiter === '') {
            $parts = str_split($value);
            return $limit > 0 && $limit !== PHP_INT_MAX ? array_slice($parts, 0, $limit) : $parts;
        }
        if ($limit === 0) {
            return [];
        }
        return $limit === PHP_INT_MAX ? explode($delimiter, $value) : explode($delimiter, $value, $limit);
    }

    private static function slice(mixed $value, int $start, ?int $length): mixed
    {
        if (is_string($value)) {
            return $length === null ? substr($value, $start) : substr($value, $start, $length);
        }
        $array = self::toArray($value);
        return $length === null ? array_slice($array, $start) : array_slice($array, $start, $length);
    }

    private static function reverse(mixed $value): mixed
    {
        return is_string($value) ? strrev($value) : array_reverse(self::toArray($value));
    }

    /** @param array<array-key, mixed> $values @return list<mixed> */
    private static function sorted(array $values): array
    {
        $values = array_values($values);
        sort($values, SORT_REGULAR);
        return $values;
    }

    /** @param array<array-key, mixed> $values @return list<mixed> */
    private static function shuffled(array $values): array
    {
        $values = array_values($values);
        if (count($values) > 1) {
            shuffle($values);
        }
        return $values;
    }

    private static function traversableLength(Traversable $value): int
    {
        $count = 0;
        foreach ($value as $_) {
            if (++$count > self::MAX_COLLECTION_ITEMS) {
                throw new \LengthException('Iterable exceeds the collection item limit.');
            }
        }
        return $count;
    }

    private static function isEmpty(mixed $value): bool
    {
        if ($value === null || $value === false || $value === '' || $value === 0 || $value === 0.0 || $value === '0') {
            return true;
        }
        if (is_array($value) || $value instanceof Countable) {
            return count($value) === 0;
        }
        if ($value instanceof Traversable) {
            foreach ($value as $_) {
                return false;
            }
            return true;
        }
        return false;
    }

    private static function isNumber(mixed $value): bool
    {
        return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value));
    }

    private static function contains(mixed $haystack, mixed $needle): bool
    {
        if ($haystack instanceof Traversable) {
            $count = 0;
            foreach ($haystack as $value) {
                if (++$count > self::MAX_COLLECTION_ITEMS) {
                    throw new \LengthException('Iterable exceeds the collection item limit.');
                }
                if ($value === $needle) {
                    return true;
                }
            }
            return false;
        }
        if (is_array($haystack)) {
            return in_array($needle, $haystack, true);
        }
        return str_contains(self::stringify($haystack), self::stringify($needle));
    }

    /** @param array<string, mixed> $args */
    private static function randomValue(array $args): mixed
    {
        $values = self::arg($args, '$values', self::arg($args, '$val'));
        if (is_array($values) || $values instanceof Traversable) {
            $list = array_values(self::toArray($values));
            if ($list === []) {
                return '';
            }
            return $list[random_int(0, count($list) - 1)];
        }
        if (is_string($values) && $values !== '') {
            return $values[random_int(0, strlen($values) - 1)];
        }
        $min = (int) self::arg($args, '$min', 0);
        $max = (int) self::arg($args, '$max', PHP_INT_MAX);
        if ($max < $min) {
            [$min, $max] = [$max, $min];
        }
        return random_int($min, $max);
    }

    /** @param array<array-key, mixed> $values */
    private static function cycle(array $values, int $index): mixed
    {
        $values = array_values($values);
        $count = count($values);
        if ($count === 0) {
            return '';
        }
        $normalized = (($index % $count) + $count) % $count;
        return $values[$normalized];
    }

    private static function stringify(mixed $value): string
    {
        return match (true) {
            $value === null, $value === false => '',
            $value === true => '1',
            is_scalar($value) => (string) $value,
            $value instanceof \Stringable => (string) $value,
            default => throw new \InvalidArgumentException(sprintf('Expected scalar/stringable value, got %s.', get_debug_type($value))),
        };
    }

    private static function truthy(mixed $value): bool
    {
        return !($value === false || $value === null || $value === '' || $value === 0 || $value === 0.0 || $value === '0');
    }
}
