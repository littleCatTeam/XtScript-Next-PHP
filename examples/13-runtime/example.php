<?php

declare(strict_types=1);
require dirname(__DIR__) . '/_autoload.php';

use XtScript\Cache\ArrayCompiledTemplateCache;
use XtScript\Engine;
use XtScript\EngineOptions;
use XtScript\ExecutionBackend;
use XtScript\Loader\ArrayLoader;
use XtScript\Profiler\ArrayProfiler;

$template = <<<'XT'
foreach $items as $item
print $item
endforeach
XT;

$loader = new ArrayLoader(['page.xt' => $template]);
$compiledCache = new ArrayCompiledTemplateCache();
$profiler = new ArrayProfiler();
$engine = new Engine(
    $loader,
    new EngineOptions(executionBackend: ExecutionBackend::Auto),
    compiledTemplateCache: $compiledCache,
    profiler: $profiler,
);

echo $engine->render('page.xt', ['items' => [1, 2, 3]]), "\n";
echo 'compiled-cache=' . $compiledCache->count() . "\n";
foreach ($profiler->events() as $event) {
    echo $event['event'] . "\n";
}
