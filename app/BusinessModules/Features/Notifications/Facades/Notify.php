<?php

namespace App\BusinessModules\Features\Notifications\Facades;

use Illuminate\Support\Facades\Facade;

class Notify extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \App\BusinessModules\Features\Notifications\Services\NotificationService::class;
    }
}

