<?php

declare(strict_types=1);
require dirname(__DIR__) . '/_autoload.php';

use XtScript\Engine;
use XtScript\Loader\ArrayLoader;

$loader = new ArrayLoader([
    'helpers.tpl' => <<<'XT'
function decorate $value="";
    return "[" ~ $value ~ "]"
endfunction
XT,
    'forms' => <<<'XT'
import helpers.tpl as h
function input_name $name="guest";
    return call h@decorate $value=$name;
endfunction
XT,
    'page.html' => <<<'XT'
import forms as forms
print call forms@input_name $name=Cat;
XT,
]);

echo (new Engine($loader))->render('page.html');
