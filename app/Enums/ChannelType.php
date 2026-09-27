<?php

namespace App\Enums;

enum ChannelType: string
{
    case WhatsApp = 'whatsapp';
    case Messenger = 'messenger';

    public function label(): string
    {
        return match ($this) {
            self::WhatsApp => 'WhatsApp',
            self::Messenger => 'Messenger',
        };
    }
}
