<?php

declare(strict_types=1);

namespace Tests\Support\Reporting;

use Composer\Autoload\ClassLoader;

final class Waves23ModuleBootstrap
{
    public static function boot(): void
    {
        $root = dirname(__DIR__, 3);
        $loader = require $root.'/vendor/autoload.php';
        if ($loader instanceof ClassLoader) {
            $loader->setClassMapAuthoritative(false);
            $loader->addPsr4('App\\', $root.'/app', true);
        }
    }
}
