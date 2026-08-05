<?php

declare(strict_types=1);
require dirname(__DIR__) . '/_autoload.php';

use XtScript\Engine;
use XtScript\Loader\ArrayLoader;

$engine = new Engine(new ArrayLoader([
    'page' => <<<'XT'
print_raw Hello 
print $name
print_raw \nItems: 
print $items | join(", ")
XT,
]));

echo $engine->render('page', ['name' => '<Admin>', 'items' => ['one', 'two', 'three']]);
