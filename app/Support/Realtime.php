<?php

namespace App\Support;

/**
 * Broadcast an event to agents' browsers without ever failing the request
 * or job that sent it (e.g. while Reverb is restarting). Browsers catch up
 * on their next refresh.
 */
class Realtime
{
    public static function send(object $event): void
    {
        rescue(fn () => event($event), report: true);
    }
}
