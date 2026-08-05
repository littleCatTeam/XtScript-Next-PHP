<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$root = dirname(__DIR__);
$examples = glob($root . '/examples/[0-9][0-9]-*/example.php') ?: [];
sort($examples);
$examples[] = $root . '/examples/xtgem-compatibility/example.php';

foreach ($examples as $example) {
    if (!is_file($example)) {
        fwrite(STDERR, sprintf("Missing example: %s\n", $example));
        exit(1);
    }
    ob_start();
    try {
        require $example;
        $output = (string) ob_get_clean();
    } catch (Throwable $throwable) {
        ob_end_clean();
        fwrite(STDERR, sprintf("Example %s failed: %s\n", basename(dirname($example)), $throwable->getMessage()));
        exit(1);
    }
    if ($output === '') {
        fwrite(STDERR, sprintf("Example %s produced no output.\n", basename(dirname($example))));
        exit(1);
    }
}

printf("PASS examples: %d runnable examples.\n", count($examples));
