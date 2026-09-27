<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;

class ConversationPolicy
{
    /**
     * Admins see every chat; agents depend on the "Which chats can agents see?" setting.
     */
    public function view(User $user, Conversation $conversation): bool
    {
        return $conversation->isVisibleTo($user);
    }

    public function reply(User $user, Conversation $conversation): bool
    {
        return $this->view($user, $conversation);
    }

    /**
     * Anyone who can see a chat can assign or transfer it.
     */
    public function assign(User $user, Conversation $conversation): bool
    {
        return $this->view($user, $conversation);
    }
}
