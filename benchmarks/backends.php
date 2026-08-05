<?php

declare(strict_types=1);

require dirname(__DIR__) . '/tests/bootstrap.php';

use XtScript\Engine;
use XtScript\EngineOptions;
use XtScript\ExecutionBackend;
use XtScript\Loader\ArrayLoader;

$items = range(1, 250);
$iterations = 200;
$rounds = 5;
$cacheDirectory = sys_get_temp_dir() . '/xtscript-backend-bench-' . bin2hex(random_bytes(4));

$median = static function (array $samples): float {
    sort($samples, SORT_NUMERIC);
    return $samples[intdiv(count($samples), 2)];
};

/** @return array{evaluator:float,php_eval:float,php_file:float} */
$measure = static function (string $name, string $template) use ($items, $iterations, $rounds, $cacheDirectory, $median): array {
    $loader = new ArrayLoader([$name => $template]);
    $engines = [
        'evaluator' => new Engine($loader, new EngineOptions(executionBackend: ExecutionBackend::Evaluator, maxOutputBytes: 8_388_608)),
        'php_eval' => new Engine($loader, new EngineOptions(executionBackend: ExecutionBackend::PhpEval, maxOutputBytes: 8_388_608)),
        'php_file' => new Engine($loader, new EngineOptions(executionBackend: ExecutionBackend::PhpFile, phpFileCacheDirectory: $cacheDirectory, maxOutputBytes: 8_388_608)),
    ];

    $expected = $engines['evaluator']->render($name, ['$items' => $items]);
    foreach (['php_eval', 'php_file'] as $backend) {
        if ($engines[$backend]->render($name, ['$items' => $items]) !== $expected) {
            throw new RuntimeException(sprintf('Backend outputs differ for %s/%s.', $name, $backend));
        }
    }

    $samples = ['evaluator' => [], 'php_eval' => [], 'php_file' => []];
    for ($round = 0; $round < $rounds; ++$round) {
        foreach ($engines as $backend => $engine) {
            $started = hrtime(true);
            for ($i = 0; $i < $iterations; ++$i) {
                $engine->render($name, ['$items' => $items]);
            }
            $samples[$backend][] = (hrtime(true) - $started) / 1_000_000_000;
        }
    }

    return [
        'evaluator' => $median($samples['evaluator']),
        'php_eval' => $median($samples['php_eval']),
        'php_file' => $median($samples['php_file']),
    ];
};

$scenarios = [
    'legacy-loop' => <<<'XT'
foreach $items as $item
    if ($item % 2 == 0)
        print $item
    else
        print ($item + 1)
    endif
endforeach
XT,
    'modern-loop' => <<<'XT'
foreach $items as $item
    if $item is even
        print $item | default(0)
    else
        print ($item + 1)
    endif
endforeach
XT,
    'interpolation' => <<<'XT'
foreach $items as $item
    print Item:$item;
endforeach
XT,
];

try {
    printf("XtScript backend benchmark (PHP %s; opcache.enable_cli=%s)\n", PHP_VERSION, ini_get('opcache.enable_cli') ?: '0');
    printf("Median of %d rounds; %d warm renders/round; %d items/render\n", $rounds, $iterations, count($items));
    foreach ($scenarios as $name => $template) {
        $result = $measure($name, $template);
        printf(
            "%-16s evaluator %.4fs | php-eval %.4fs (%.2fx) | php-file %.4fs (%.2fx)\n",
            $name,
            $result['evaluator'],
            $result['php_eval'],
            $result['evaluator'] / $result['php_eval'],
            $result['php_file'],
            $result['evaluator'] / $result['php_file'],
        );
    }
} finally {
    foreach (glob($cacheDirectory . '/*') ?: [] as $file) @unlink($file);
    if (is_dir($cacheDirectory)) @rmdir($cacheDirectory);
}
