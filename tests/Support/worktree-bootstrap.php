<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';

spl_autoload_register(static function (string $class) use ($root): void {
    foreach ([
        'App\\' => $root.'/app/',
        'Tests\\' => $root.'/tests/',
    ] as $prefix => $directory) {
        if (! str_starts_with($class, $prefix)) {
            continue;
        }

        $path = $directory.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        if (is_file($path)) {
            require $path;
        }

        return;
    }
}, true, true);
