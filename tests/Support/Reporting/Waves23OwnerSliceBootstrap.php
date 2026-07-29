<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';

$root = dirname(__DIR__, 3);

spl_autoload_register(
    static function (string $class) use ($root): void {
        $prefixes = [
            'App\\' => $root.'/app/',
            'Tests\\' => $root.'/tests/',
        ];

        foreach ($prefixes as $prefix => $directory) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }

            $path = $directory.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
            if (is_file($path)) {
                require $path;
            }
        }
    },
    true,
    true,
);
