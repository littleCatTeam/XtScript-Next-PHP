<?php

declare(strict_types=1);
require dirname(__DIR__) . '/_autoload.php';

use XtScript\Engine;
use XtScript\Loader\ArrayLoader;

$source = <<<'XT'
foreach $items as $key => $item
    if $loop.first
        print first:
    endif
    print $key=$item;
else
    print empty
endforeach
print_raw |
switch $status
case "ok"
    print OK
break
default
    print UNKNOWN
endswitch
XT;

echo (new Engine(new ArrayLoader()))->renderString($source, ['items' => ['a' => 'A', 'b' => 'B'], 'status' => 'ok']);
