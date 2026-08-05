<?php

declare(strict_types=1);
require dirname(__DIR__) . '/_autoload.php';

use XtScript\Engine;
use XtScript\Loader\ArrayLoader;

$loader = new ArrayLoader([
    'card.xt' => <<<'XT'
print_raw <article><header>
print $slots.header
print_raw </header><main>
print $slot
print_raw </main><footer>
print $title
print_raw </footer></article>
XT,
    'page.xt' => <<<'XT'
component card.xt $title="Card title";
    slot header
        print_raw <b>Header</b>
    endslot
    print_raw <em>Body</em>
endcomponent
XT,
]);

echo (new Engine($loader))->render('page.xt');
