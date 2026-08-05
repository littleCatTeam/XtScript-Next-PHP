<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
$failures = [];
$count = 0;

foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php' || str_contains($file->getPathname(), '/vendor/')) {
        continue;
    }

    ++$count;
    $command = sprintf('%s -l %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($file->getPathname()));
    exec($command, $output, $exitCode);
    if ($exitCode !== 0) {
        $failures[] = $file->getPathname() . ': ' . implode("\n", $output);
    }
    $output = [];
}

if ($failures !== []) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

printf("Lint: %d PHP files passed.\n", $count);
