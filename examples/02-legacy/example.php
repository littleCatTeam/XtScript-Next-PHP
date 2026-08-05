<?php

declare(strict_types=1);
require dirname(__DIR__) . '/_autoload.php';

use XtScript\Engine;
use XtScript\Loader\ArrayLoader;

$source = <<<'HTML'
<header>HTML outside parser wrapper</header>
<!--parser:xtscript-->
assign $name = "Cat"
function greet $who="World";
    return Hello $who
endfunction
print call greet $who=$name;
print_raw |
foreach $items as $item
    if $item == 2
        continue
    endif
    print $item
endforeach
goto @done
print SHOULD_NOT_PRINT
@done
<!--/parser:xtscript-->
<footer>plain HTML</footer>
HTML;

echo (new Engine(new ArrayLoader()))->renderString($source, ['items' => [1, 2, 3]]);
