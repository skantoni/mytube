<?php
declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $prefix  = 'MyTube\\';
    $baseDir = __DIR__ . '/app/';
    $len     = strlen($prefix);

    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative = substr($class, $len);
    $file     = $baseDir . str_replace('\\', '/', $relative) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});
