<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$composer = $root . '/vendor/autoload.php';
require is_file($composer) ? $composer : $root . '/tests/bootstrap.php';
