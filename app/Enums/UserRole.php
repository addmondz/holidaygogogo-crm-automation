<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Agent = 'agent';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
