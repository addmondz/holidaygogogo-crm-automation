<?php

namespace App\Services\Inbox;

use App\Models\Contact;
use App\Models\User;

/**
 * Fills {name}, {first_name}, {phone} and {agent_name} in quick replies and
 * blast variables. A fallback can be given after a bar: "Hi {first_name|there}".
 */
class Placeholders
{
    public const PATTERN = '/\{(name|first_name|phone|agent_name)(?:\|([^}]*))?\}/';

    public static function fill(string $text, ?Contact $contact, ?User $agent = null): string
    {
        return (string) preg_replace_callback(self::PATTERN, function (array $match) use ($contact, $agent) {
            $value = match ($match[1]) {
                'name' => $contact?->name,
                'first_name' => $contact?->first_name,
                'phone' => $contact?->phone ? '+'.$contact->phone : null,
                'agent_name' => $agent?->name,
            };

            return filled($value) ? (string) $value : ($match[2] ?? '');
        }, $text);
    }
}
