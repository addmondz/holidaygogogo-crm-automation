<?php

namespace App\Enums;

enum BroadcastStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Sending = 'sending';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Failed = 'failed';
}
