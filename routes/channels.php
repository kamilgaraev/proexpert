<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}.{interface}', function ($user, $id, $interface) {
    return (int) $user->id === (int) $id && in_array($interface, ['admin', 'lk']);
});
