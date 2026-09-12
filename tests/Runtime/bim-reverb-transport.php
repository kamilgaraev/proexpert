<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as ValidationFactory;
use Laravel\Reverb\ConfigApplicationProvider;
use Laravel\Reverb\Contracts\ApplicationProvider;
use Laravel\Reverb\Contracts\Logger;
use Laravel\Reverb\Loggers\NullLogger;
use Laravel\Reverb\ServerProviderManager;
use Laravel\Reverb\Servers\Reverb\Factory;
use React\EventLoop\Loop;

$app = new Application(dirname(__DIR__, 2));
$app->instance('config', new Repository(['reverb' => ['default' => 'reverb', 'servers' => ['reverb' => ['scaling' => ['enabled' => false]]]]]));
$app->instance('validator', new ValidationFactory(new Translator(new ArrayLoader, 'en'), $app));
$app->instance(Logger::class, new NullLogger);
$app->instance(ServerProviderManager::class, new ServerProviderManager($app));
$app->instance(ApplicationProvider::class, new ConfigApplicationProvider(collect([[
    'app_id' => 'bim-local-test', 'key' => 'bim-local-key', 'secret' => 'bim-local-secret',
    'ping_interval' => 60, 'activity_timeout' => 30, 'allowed_origins' => ['*'], 'max_message_size' => 10000,
]])));
Facade::setFacadeApplication($app);
$server = Factory::make(host: '127.0.0.1', port: '18079');
Loop::addTimer(180, static fn () => Loop::stop());
fwrite(STDOUT, "Reverb transport fixture listening on 127.0.0.1:18079; no application bootstrap or database\n");
$server->start();
