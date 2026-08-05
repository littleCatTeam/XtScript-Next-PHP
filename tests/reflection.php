<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/src'));
foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        require_once $file->getPathname();
    }
}

$failures = [];
foreach (get_declared_classes() as $class) {
    if (!str_starts_with($class, 'XtScript\\')) {
        continue;
    }
    $reflection = new ReflectionClass($class);
    foreach ($reflection->getProperties() as $property) {
        if ($property->getDeclaringClass()->getName() === $class && !$property->hasType()) {
            $failures[] = sprintf('%s::$%s has no declared type.', $class, $property->getName());
        }
    }
    foreach ($reflection->getMethods() as $method) {
        if ($method->getDeclaringClass()->getName() !== $class) {
            continue;
        }
        if (!$method->hasReturnType() && !str_starts_with($method->getName(), '__')) {
            $failures[] = sprintf('%s::%s() has no return type.', $class, $method->getName());
        }
        foreach ($method->getParameters() as $parameter) {
            if (!$parameter->hasType()) {
                $failures[] = sprintf('%s::%s($%s) has no parameter type.', $class, $method->getName(), $parameter->getName());
            }
        }
    }
}

foreach (get_declared_interfaces() as $interface) {
    if (!str_starts_with($interface, 'XtScript\\')) {
        continue;
    }
    $reflection = new ReflectionClass($interface);
    foreach ($reflection->getMethods() as $method) {
        if (!$method->hasReturnType()) {
            $failures[] = sprintf('%s::%s() has no return type.', $interface, $method->getName());
        }
        foreach ($method->getParameters() as $parameter) {
            if (!$parameter->hasType()) {
                $failures[] = sprintf('%s::%s($%s) has no parameter type.', $interface, $method->getName(), $parameter->getName());
            }
        }
    }
}

if ($failures !== []) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Reflection strict type audit: PASS\n";
