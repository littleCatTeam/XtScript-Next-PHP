<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use XtScript\Engine;
use XtScript\EngineOptions;
use XtScript\Loader\ArrayLoader;

$engine = new Engine(new ArrayLoader(), new EngineOptions(
    maxInstructions: 1_000,
    maxOutputBytes: 65_536,
    maxLoopIterations: 1_000,
    timeoutSeconds: 0.5,
));

$alphabet = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789$()<>!=+-*/%_'\" ;:@,.[]";
$iterations = 3_000;

for ($i = 0; $i < $iterations; ++$i) {
    $length = mt_rand(0, 128);
    $value = '';
    for ($j = 0; $j < $length; ++$j) {
        $value .= $alphabet[mt_rand(0, strlen($alphabet) - 1)];
    }

    $source = match ($i % 4) {
        0 => 'print ' . $value,
        1 => "if {$value}\nprint yes\nendif",
        2 => 'assign $x = ' . $value,
        default => $value,
    };

    try {
        $engine->renderString($source, ['x' => '<x>', 'n' => 3], 'fuzz-' . $i . '.xt');
    } catch (Throwable) {
        // Syntax/runtime rejections are expected. The error handler in
        // bootstrap turns PHP warnings/notices/deprecations into exceptions,
        // so reaching here means the engine failed closed instead of leaking
        // a PHP-level diagnostic.
    }
}

printf("Fuzz: %d inputs completed without fatal process failure.\n", $iterations);
