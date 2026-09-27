<?php

namespace App\Enums;

enum MessageType: string
{
    case Text = 'text';
    case Image = 'image';
    case Video = 'video';
    case Audio = 'audio';
    case Document = 'document';
    case Sticker = 'sticker';
    case Location = 'location';
    case Contacts = 'contacts';
    case Template = 'template';
    case Interactive = 'interactive';
    case Reaction = 'reaction';
    case Unsupported = 'unsupported';
    case Event = 'event';

    public function isMedia(): bool
    {
        return in_array($this, [self::Image, self::Video, self::Audio, self::Document, self::Sticker], true);
    }

    public static function fromMime(?string $mime): self
    {
        return match (true) {
            str_starts_with((string) $mime, 'image/') => self::Image,
            str_starts_with((string) $mime, 'video/') => self::Video,
            str_starts_with((string) $mime, 'audio/') => self::Audio,
            default => self::Document,
        };
    }
}
