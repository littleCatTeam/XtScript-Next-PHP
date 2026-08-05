<?php

declare(strict_types=1);
require dirname(__DIR__) . '/_autoload.php';

use XtScript\Engine;
use XtScript\Loader\ArrayLoader;

$source = <<<'XT'
capture $html
    print_raw <strong>captured</strong>
endcapture
print $html
print_raw |
with $name="inner";
    print $name | upper
endwith
print_raw |
apply trim | upper
    print_raw   transformed text  
endapply
print_raw |
autoescape off
    print $trusted
endautoescape
print_raw |
verbatim
print $this_is_literal
endverbatim
XT;

echo (new Engine(new ArrayLoader()))->renderString($source, ['trusted' => '<i>trusted</i>']);
