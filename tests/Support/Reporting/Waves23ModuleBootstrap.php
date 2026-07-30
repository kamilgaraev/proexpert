<?php

declare(strict_types=1);

namespace Tests\Support\Reporting;

use Composer\Autoload\ClassLoader;

final class Waves23ModuleBootstrap
{
    public static function boot(): void
    {
        $root = dirname(__DIR__, 3);
        require $root.'/vendor/autoload.php';
        $moduleLoader = new ClassLoader;
        $moduleLoader->addPsr4('App\\', $root.'/app');
        $moduleLoader->addPsr4('Tests\\', $root.'/tests');
        $moduleLoader->register(true);
    }
}
