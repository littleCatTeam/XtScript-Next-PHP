<?php

declare(strict_types=1);
require dirname(__DIR__) . '/_autoload.php';

use XtScript\Contract\PluginInterface;
use XtScript\Engine;
use XtScript\EngineOptions;
use XtScript\Loader\ArrayLoader;
use XtScript\Plugin\FunctionContext;
use XtScript\Plugin\PluginTrait;
use XtScript\Plugin\XtTagDefinition;

final class XtDemoPlugin implements PluginInterface
{
    use PluginTrait;
    public function getName(): string { return 'xt-demo'; }
    public function getXtTags(): iterable
    {
        yield new XtTagDefinition('hello', static fn (FunctionContext $context, string $name, array $attributes, ?string $body): string => 'Hello ' . ($attributes['name'] ?? 'guest') . ($body === null ? '' : ' [' . $body . ']'));
        yield new XtTagDefinition('*', static fn (FunctionContext $context, string $name, array $attributes, ?string $body, string $raw): string => '[unknown:' . $name . ']');
    }
}

$engine = new Engine(
    new ArrayLoader(),
    new EngineOptions(pluginTagPrefix: 'cms'),
    plugins: [new XtDemoPlugin()],
);
echo $engine->renderString('<!--parser:xtscript--><!--/parser:xtscript--><cms:hello name="Cat">body</cms:hello> <cms:future />');
