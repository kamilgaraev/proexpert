<?php

declare(strict_types=1);

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

require __DIR__.'/runtime.php';
$data = \Tests\Runtime\BimDeviceAcceptance\descriptor();
$env = $data['environment'];
$app = new Application(dirname(__DIR__, 3));
$app->instance('config', new Repository(['reverb' => ['default' => 'reverb', 'servers' => ['reverb' => ['scaling' => ['enabled' => false]]]]]));
$app->instance('validator', new ValidationFactory(new Translator(new ArrayLoader, 'en'), $app));
$app->instance(Logger::class, new NullLogger);
$app->instance(ServerProviderManager::class, new ServerProviderManager($app));
$app->instance(ApplicationProvider::class, new ConfigApplicationProvider(collect([[
    'app_id' => $env['REVERB_APP_ID'], 'key' => $env['REVERB_APP_KEY'], 'secret' => $env['REVERB_APP_SECRET'],
    'ping_interval' => 60, 'activity_timeout' => 30,
    'allowed_origins' => ['127.0.0.1', 'localhost'],
    'max_message_size' => 10000,
]])));
Facade::setFacadeApplication($app);
$server = Factory::make(host: '127.0.0.1', port: $env['REVERB_PORT']);
Loop::addPeriodicTimer(1, static function (): void {
    try {
        \Tests\Runtime\BimDeviceAcceptance\descriptor();
    } catch (RuntimeException) {
        Loop::stop();
    }
});
$server->start();
