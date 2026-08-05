<?php

declare(strict_types=1);
require dirname(__DIR__) . '/_autoload.php';

use XtScript\Engine;
use XtScript\Loader\ArrayLoader;

$loader = new ArrayLoader([
    'layout.xt' => <<<'XT'
print_raw <html><body>
block content
print Base
endblock
print_raw </body></html>
XT,
    'middle.xt' => <<<'XT'
extends layout.xt
block content
parent
print +Middle
endblock
XT,
    'page.xt' => <<<'XT'
extends middle.xt
block content
print Page+
parent
endblock
XT,
]);

echo (new Engine($loader))->render('page.xt');
