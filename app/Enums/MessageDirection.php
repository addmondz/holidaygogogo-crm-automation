<?php

namespace App\Enums;

enum MessageDirection: string
{
    case Inbound = 'inbound';
    case Outbound = 'outbound';
    // Timeline entries such as "Chat assigned to Aisyah".
    case Event = 'event';
}
