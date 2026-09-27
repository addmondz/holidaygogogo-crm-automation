<?php

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function (User $user, int $id) {
    return $user->id === $id;
});

// Light "something changed" pings for the chat list. Payloads only carry IDs;
// each browser re-fetches the list, which applies the visibility rules.
Broadcast::channel('inbox', function (User $user) {
    return $user->is_active;
});

// Full message payloads, only for agents allowed to see the chat.
Broadcast::channel('conversation.{conversation}', function (User $user, Conversation $conversation) {
    return $user->is_active && $conversation->isVisibleTo($user);
});
