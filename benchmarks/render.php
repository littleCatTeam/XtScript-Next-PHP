<?php

declare(strict_types=1);

require dirname(__DIR__) . '/tests/bootstrap.php';

use XtScript\Engine;
use XtScript\EngineOptions;
use XtScript\Loader\ArrayLoader;

$template = <<<'XT'
foreach $items as $item
    if $item is even
        print $item | default(0)
    else
        print ($item + 1)
    endif
endforeach
XT;

$items = range(1, 250);
$iterations = 250;

/** @return array{seconds:float,renders_per_second:float,hash:string} */
$measure = static function (bool $compiledCache) use ($template, $items, $iterations): array {
    $engine = new Engine(
        new ArrayLoader(['bench.xt' => $template]),
        new EngineOptions(
            cacheCompiledTemplates: $compiledCache,
            compiledCacheSize: 64,
            expressionCacheSize: 256,
            maxOutputBytes: 8_388_608,
        ),
    );

    $hash = '';
    $started = hrtime(true);
    for ($i = 0; $i < $iterations; ++$i) {
        $output = $engine->render('bench.xt', ['$items' => $items]);
        $hash = hash('sha256', $output);
    }
    $seconds = (hrtime(true) - $started) / 1_000_000_000;

    return [
        'seconds' => $seconds,
        'renders_per_second' => $seconds > 0.0 ? $iterations / $seconds : 0.0,
        'hash' => $hash,
    ];
};

$warm = $measure(true);
$uncached = $measure(false);

if ($warm['hash'] !== $uncached['hash']) {
    throw new RuntimeException('Benchmark outputs differ between cache modes.');
}

printf("XtScript benchmark (PHP %s)\n", PHP_VERSION);
printf("Template renders: %d; items/render: %d\n", $iterations, count($items));
printf("Compiled cache ON : %.4fs (%.1f renders/s)\n", $warm['seconds'], $warm['renders_per_second']);
printf("Compiled cache OFF: %.4fs (%.1f renders/s)\n", $uncached['seconds'], $uncached['renders_per_second']);
printf("Peak memory       : %.2f MiB\n", memory_get_peak_usage(true) / 1048576);
