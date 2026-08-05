<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use XtScript\Plugin\CorePlugin;

$root = dirname(__DIR__);
$core = new CorePlugin();
$checks = [
    'core function' => [$core->getFunctions(), $root . '/docs/core-functions.md'],
    'core filter' => [$core->getFilters(), $root . '/docs/filters.md'],
    'core test' => [$core->getTests(), $root . '/docs/tests.md'],
    'core tag' => [$core->getTags(), $root . '/docs/syntax.md'],
];

$missing = [];
foreach ($checks as $kind => [$definitions, $path]) {
    $content = file_get_contents($path);
    if ($content === false) {
        throw new RuntimeException(sprintf('Cannot read %s.', $path));
    }
    foreach ($definitions as $definition) {
        $name = method_exists($definition, 'getName') ? $definition->getName() : $definition->name;
        if (!str_contains($content, '`' . $name . '`')) {
            $missing[] = sprintf('%s `%s` is missing from %s', $kind, $name, basename($path));
        }
    }
}

if ($missing !== []) {
    fwrite(STDERR, implode(PHP_EOL, $missing) . PHP_EOL);
    exit(1);
}

$requiredDocs = [
    'README.md', 'architecture.md', 'getting-started.md', 'engine-api.md', 'configuration.md',
    'syntax.md', 'core-functions.md', 'filters.md', 'tests.md', 'loaders.md', 'plugins.md',
    'xt-tags.md', 'caching-performance.md', 'escaping.md', 'formatting-assets.md', 'contracts-analysis.md', 'cli.md',
    'security.md', 'exceptions.md', 'compatibility.md', 'regex.md',
];
foreach ($requiredDocs as $file) {
    if (!is_file($root . '/docs/' . $file)) {
        fwrite(STDERR, sprintf("Required documentation file %s is missing.\n", $file));
        exit(1);
    }
}

printf("PASS docs: core registries are documented (%d functions, %d filters, %d tests, %d tags).\n",
    iterator_count($core->getFunctions()),
    iterator_count($core->getFilters()),
    iterator_count($core->getTests()),
    iterator_count($core->getTags()),
);
