<?php

use App\Support\HotelLiveChannelAccess;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('hotel.operations', function ($user) {
    return HotelLiveChannelAccess::allows($user)
        ? ['id' => $user->id]
        : false;
}, ['guards' => ['api']]);
