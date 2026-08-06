<?php

declare(strict_types=1);

error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): void {
    if (error_reporting() === 0) {
        return;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

spl_autoload_register(static function (string $class): void {
    $prefix = 'XtScript\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});
