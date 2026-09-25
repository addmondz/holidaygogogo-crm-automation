<?php

namespace App\Models;

use Database\Factories\QuickReplyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'is_shared', 'shortcut', 'title', 'body', 'attachment_path', 'attachment_name', 'attachment_mime'])]
class QuickReply extends Model
{
    /** @use HasFactory<QuickReplyFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['is_shared' => 'boolean'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Shared replies plus the user's own personal replies.
     *
     * @param  Builder<QuickReply>  $query
     */
    public function scopeAvailableTo(Builder $query, User $user): void
    {
        $query->where(fn (Builder $q) => $q->where('is_shared', true)->orWhere('user_id', $user->id));
    }

    public function canBeManagedBy(User $user): bool
    {
        return $user->isAdmin() || (! $this->is_shared && $this->user_id === $user->id);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(?User $viewer = null): array
    {
        return [
            'id' => $this->id,
            'shortcut' => $this->shortcut,
            'title' => $this->title,
            'body' => $this->body,
            'is_shared' => $this->is_shared,
            'owner' => $this->user?->name,
            'attachment' => $this->attachment_path ? [
                'name' => $this->attachment_name,
                'mime' => $this->attachment_mime,
                'url' => route('quick-replies.attachment', $this),
            ] : null,
            'can_manage' => $viewer ? $this->canBeManagedBy($viewer) : false,
        ];
    }
}
