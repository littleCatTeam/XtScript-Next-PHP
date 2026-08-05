<?php

declare(strict_types=1);
require dirname(__DIR__) . '/_autoload.php';

use XtScript\Cache\ArrayFragmentCache;
use XtScript\Engine;
use XtScript\Loader\ArrayLoader;

$source = <<<'XT'
cache "fragment";60
    print $value
endcache
print_raw |
once "asset"
    push scripts
        print_raw <script src="/app.js"></script>
    endpush
endonce
prepend scripts
    print_raw <script src="/first.js"></script>
endprepend
stack scripts
XT;

$cache = new ArrayFragmentCache();
$engine = new Engine(new ArrayLoader(), fragmentCache: $cache);
echo $engine->renderString($source, ['value' => 'first'], 'cache-demo.xt');
echo "\n";
echo $engine->renderString($source, ['value' => 'second'], 'cache-demo.xt');
