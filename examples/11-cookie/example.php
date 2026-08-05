<?php

declare(strict_types=1);
require dirname(__DIR__) . '/_autoload.php';

use XtScript\Engine;
use XtScript\Http\ArrayCookieStore;
use XtScript\Loader\ArrayLoader;
use XtScript\Plugin\CookiePlugin;

$store = new ArrayCookieStore();
$engine = new Engine(new ArrayLoader(), plugins: [new CookiePlugin($store, 'example-site')]);

$source = <<<'XT'
call cookie::set $name=theme;$val=dark;
print call cookie::get $name=theme;$default=light;
call cookie::delete $name=theme;
print_raw |
print call cookie::get $name=theme;$default=missing;
XT;

echo $engine->renderString($source, name: 'cookies.xt');
