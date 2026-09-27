<?php

namespace App\Enums;

/**
 * Which conversations a non-admin agent can see. Admins always see everything.
 */
enum InboxVisibility: string
{
    case OwnAndUnassigned = 'own_and_unassigned';
    case All = 'all';
    case Own = 'own';

    public function label(): string
    {
        return match ($this) {
            self::OwnAndUnassigned => 'Own chats + unassigned chats',
            self::All => 'All chats',
            self::Own => 'Only chats assigned to them',
        };
    }
}
