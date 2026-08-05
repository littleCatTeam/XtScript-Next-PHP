<?php

declare(strict_types=1);
require dirname(__DIR__) . '/_autoload.php';

use XtScript\Context;
use XtScript\Contract\PluginInterface;
use XtScript\Engine;
use XtScript\Loader\ArrayLoader;
use XtScript\Plugin\BlockTagDefinition;
use XtScript\Plugin\FilterDefinition;
use XtScript\Plugin\FunctionContext;
use XtScript\Plugin\FunctionDefinition;
use XtScript\Plugin\PluginTrait;
use XtScript\Plugin\TagDefinition;
use XtScript\Plugin\TestDefinition;
use XtScript\Plugin\XtTagDefinition;

final class DemoPlugin implements PluginInterface
{
    use PluginTrait;

    public function getName(): string { return 'demo'; }

    public function getFunctions(): iterable
    {
        yield new FunctionDefinition('demo::hello', static fn (FunctionContext $context, array $args): string => 'Hello ' . ($args['$name'] ?? 'guest'));
    }

    public function getFilters(): iterable
    {
        yield new FilterDefinition('bracket', static fn (Context $context, mixed $value, array $args, bool $defined): string => '[' . (string) $value . ']');
    }

    public function getTests(): iterable
    {
        yield new TestDefinition('positive', static fn (Context $context, mixed $value, array $args, bool $defined): bool => $defined && is_numeric($value) && (float) $value > 0);
    }

    public function getTags(): iterable
    {
        yield new TagDefinition('bang', static fn (FunctionContext $context, string $arguments): string => strtoupper(trim($arguments)) . '!');
    }

    public function getBlockTags(): iterable
    {
        yield new BlockTagDefinition('twice', 'endtwice', static fn (FunctionContext $context, string $arguments, Closure $render): string => $render() . $render());
    }

    public function getXtTags(): iterable
    {
        yield new XtTagDefinition('hello', static fn (FunctionContext $context, string $name, array $attributes, ?string $body): string => 'XT:' . ($attributes['name'] ?? 'guest') . ($body === null ? '' : ':' . $body));
    }

    public function getGlobals(): array
    {
        return ['app_name' => 'XtScript'];
    }
}

$source = <<<'XT'
print call demo::hello $name=Cat;
print_raw |
print $app_name | bracket
print_raw |
if 3 is positive
    bang plugin
endif
print_raw |
twice
    print X
endtwice
XT;

$engine = new Engine(new ArrayLoader(), plugins: [new DemoPlugin()]);
echo $engine->renderString($source);
echo '|';
echo $engine->renderString('<!--parser:xtscript--><!--/parser:xtscript--><xt:hello name="Cat">body</xt:hello>');
