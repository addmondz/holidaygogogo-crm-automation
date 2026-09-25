<?php

namespace App\Enums;

enum MessageStatus: string
{
    case Received = 'received';
    case Pending = 'pending';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Read = 'read';
    case Failed = 'failed';

    /**
     * Receipts can arrive out of order, so never move a message "backwards".
     */
    public function rank(): int
    {
        return match ($this) {
            self::Received, self::Pending => 0,
            self::Sent => 1,
            self::Delivered => 2,
            self::Read => 3,
            self::Failed => 4,
        };
    }
}
