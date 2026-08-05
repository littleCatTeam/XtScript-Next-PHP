<?php

declare(strict_types=1);
require dirname(__DIR__) . '/_autoload.php';

use XtScript\Engine;
use XtScript\Loader\ArrayLoader;

$source = <<<'XT'
assign $items = [1, 2, 3]
assign $user = {"name": " Cat ", role: "admin"}
print $missing | default("Guest")
print_raw |
print $user.name | trim | upper
print_raw |
print $items | join("-")
print_raw |
print $missing ?? "fallback"
print_raw |
print $user.role == "admin" ? "allowed" : "denied"
print_raw |
if 2 in $items and $items is iterable
    print yes
endif
XT;

echo (new Engine(new ArrayLoader()))->renderString($source);
