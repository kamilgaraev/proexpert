<?php

declare(strict_types=1);

use App\Broadcasting\UserChannel;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}.{interface}', UserChannel::class);
