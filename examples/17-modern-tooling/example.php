<?php

declare(strict_types=1);

require dirname(__DIR__) . '/_autoload.php';

use XtScript\Engine;
use XtScript\EngineOptions;
use XtScript\ExecutionBackend;
use XtScript\Loader\ArrayLoader;
use XtScript\TemplateContract;

$loader = new ArrayLoader([
    'main' => 'include partial' . "\n" . 'print $name:$count',
    'partial' => 'print PARTIAL-',
]);
$cache = sys_get_temp_dir() . '/xtscript-example-aot-' . bin2hex(random_bytes(4));
try {
    $engine = new Engine($loader, new EngineOptions(
        strictVariables: true,
        executionBackend: ExecutionBackend::PhpFile,
        phpFileCacheDirectory: $cache,
    ));
    $contract = new TemplateContract(['name' => 'string', 'count' => 'int']);
    echo $engine->renderWithContract('main', $contract, ['name' => 'Cat', 'count' => 2]), "\n";
    echo 'deps=', implode(',', $engine->dependencies('main')->transitiveDependenciesOf('main')), "\n";
} finally {
    foreach (glob($cache . '/*') ?: [] as $file) @unlink($file);
    if (is_dir($cache)) rmdir($cache);
}
