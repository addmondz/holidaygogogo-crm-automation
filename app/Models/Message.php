<?php

namespace App\Models;

use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'conversation_id', 'direction', 'type', 'body', 'media', 'meta',
    'external_id', 'status', 'error', 'user_id', 'created_at',
])]
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'direction' => MessageDirection::class,
            'type' => MessageType::class,
            'status' => MessageStatus::class,
            'media' => 'array',
            'meta' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Move the delivery status forward (receipts may arrive out of order).
     */
    public function advanceStatus(MessageStatus $status, ?string $error = null): bool
    {
        if ($this->status === MessageStatus::Failed || $status->rank() <= $this->status->rank()) {
            return false;
        }

        $this->status = $status;
        $this->error = $error ?? $this->error;
        $this->save();

        return true;
    }

    /**
     * A short line for the chat list, e.g. "📷 Photo" or the first words of the text.
     */
    public function preview(): string
    {
        $label = match ($this->type) {
            MessageType::Image => '📷 Photo',
            MessageType::Video => '🎥 Video',
            MessageType::Audio => '🎤 Voice message',
            MessageType::Document => '📄 '.($this->media['filename'] ?? 'Document'),
            MessageType::Sticker => 'Sticker',
            MessageType::Location => '📍 Location',
            MessageType::Contacts => '👤 Contact card',
            MessageType::Reaction => 'Reacted '.($this->body ?? ''),
            default => null,
        };

        $text = trim((string) $this->body);

        // Templates start with a *bold* header; the chat list shows plain text.
        if ($this->type === MessageType::Template) {
            $text = trim(str_replace('*', '', $text));
        }

        if ($label && $text !== '' && $this->type !== MessageType::Reaction) {
            $label .= ': '.$text;
        }

        return Str::limit($label ?? ($text !== '' ? $text : 'Message'), 120);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $media = $this->media;

        if ($media && ! empty($media['path'])) {
            $media['url'] = route('media.show', $this);
        }

        unset($media['path']);

        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'direction' => $this->direction->value,
            'type' => $this->type->value,
            'body' => $this->body,
            'media' => $media ?: null,
            'meta' => $this->meta,
            'status' => $this->status->value,
            'error' => $this->error,
            'user' => $this->user ? ['id' => $this->user->id, 'name' => $this->user->name] : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
