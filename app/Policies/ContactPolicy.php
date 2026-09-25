<?php

namespace App\Policies;

use App\Models\Contact;
use App\Models\User;

class ContactPolicy
{
    public function view(User $user, Contact $contact): bool
    {
        return $user->isAdmin() || Contact::query()->whereKey($contact->id)->visibleTo($user)->exists();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Contact $contact): bool
    {
        return $this->view($user, $contact);
    }

    public function delete(User $user, Contact $contact): bool
    {
        return $user->isAdmin();
    }
}
