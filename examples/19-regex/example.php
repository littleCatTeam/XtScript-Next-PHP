<?php

declare(strict_types=1);

$autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
require is_file($autoload) ? $autoload : dirname(__DIR__, 2) . '/tests/bootstrap.php';

use XtScript\Engine;
use XtScript\Loader\ArrayLoader;

$engine = new Engine(new ArrayLoader());

$template = <<<'XTS'
if $input matches /^(?<name>\p{L}+)-(?<id>\d+)$/u
    assign $match = ($input | regex_match(/^(?<name>\p{L}+)-(?<id>\d+)$/u))
    print $match.name
    print ":"
    print $match.id
endif

print "a1 b22 c333" | regex_count(/\d+/)
print "a,,b,c" | regex_split(/,/, -1, "no_empty") | join("|")
print "abc123" | regex_replace(/(\d+)/, "[$1]")
XTS;

echo $engine->renderString($template, ['input' => 'test-42']);
