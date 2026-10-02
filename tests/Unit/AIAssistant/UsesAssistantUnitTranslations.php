<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use Psr\Log\NullLogger;

trait UsesAssistantUnitTranslations
{
    private mixed $previousFacadeApplication;

    private Container $previousContainer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $this->previousContainer = Container::getInstance();
        $root = dirname(__DIR__, 3);
        $application = new Application($root);
        $application->instance('config', new Repository(['app' => ['locale' => 'ru', 'fallback_locale' => 'ru']]));
        $application->instance('translator', new Translator(new FileLoader(new Filesystem, $root.'/lang'), 'ru'));
        $application->instance('log', new NullLogger);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($application);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }
}
