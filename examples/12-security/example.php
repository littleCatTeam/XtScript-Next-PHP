<?php

declare(strict_types=1);
require dirname(__DIR__) . '/_autoload.php';

use XtScript\Engine;
use XtScript\Loader\ArrayLoader;
use XtScript\Security\AllowListSecurityPolicy;

$loader = new ArrayLoader([
    'safe.xt' => 'print $name | upper',
    'other.xt' => 'print blocked',
]);

$policy = new AllowListSecurityPolicy(
    functions: [],
    filters: ['upper'],
    tests: ['defined'],
    tags: ['print'],
    templates: ['safe.xt'],
);

$engine = new Engine($loader, securityPolicy: $policy);
echo $engine->render('safe.xt', ['name' => 'safe']);
