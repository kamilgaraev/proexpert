<?php

declare(strict_types=1);

use App\Broadcasting\OrganizationUserChannel;
use App\Broadcasting\UserChannel;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}.{interface}.global', UserChannel::class);
Broadcast::channel(
    'App.Models.User.{id}.{interface}.org.{organizationId}',
    OrganizationUserChannel::class
);
