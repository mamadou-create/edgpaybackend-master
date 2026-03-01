<?php

use Illuminate\Support\Facades\Broadcast;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

// Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
//     return (int) $user->id === (int) $id;
// });



// Canal privé pour la messagerie entre deux utilisateurs
// Broadcast::channel('chat.{user1}.{user2}', function (User $user, string $user1, string $user2) {
//     return (int) $user->id === (int) $user1 || (int) $user->id === (int) $user2;
// });


Broadcast::channel('chat.{userId}', function ($user, $userId) {
    return (string) $user->id === (string) $userId;
});