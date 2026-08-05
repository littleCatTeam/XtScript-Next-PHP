<?php

declare(strict_types=1);
require dirname(__DIR__) . '/_autoload.php';

use XtScript\Engine;
use XtScript\Loader\ArrayLoader;
use XtScript\Loader\FilesystemLoader;

$arrayEngine = new Engine(new ArrayLoader(['page' => 'print Array:$name']));
echo $arrayEngine->render('page', ['name' => 'OK']), "\n";

$filesystemEngine = new Engine(new FilesystemLoader(dirname(__DIR__) . '/templates'));
echo $filesystemEngine->render('main', ['name' => 'FS', 'items' => ['one']]);
