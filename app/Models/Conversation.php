<?php

namespace App\Models;

use App\Enums\ConversationStatus;
use App\Enums\InboxVisibility;
use App\Support\CrmSettings;
use Carbon\CarbonInterface;
use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'channel_id', 'contact_id', 'external_id', 'assigned_user_id', 'status',
    'last_message_at', 'last_inbound_at', 'last_message_preview', 'unread_count',
])]
class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory;

    /**
     * Meta only allows free-form messages within 24 hours of the customer's last message.
     */
    public const SERVICE_WINDOW_HOURS = 24;

    protected function casts(): array
    {
        return [
            'status' => ConversationStatus::class,
            'last_message_at' => 'datetime',
            'last_inbound_at' => 'datetime',
            'unread_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Channel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function windowExpiresAt(): ?CarbonInterface
    {
        return $this->last_inbound_at?->copy()->addHours(self::SERVICE_WINDOW_HOURS);
    }

    public function isWindowOpen(): bool
    {
        return $this->windowExpiresAt()?->isFuture() ?? false;
    }

    /**
     * @param  Builder<Conversation>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->isAdmin()) {
            return;
        }

        match (CrmSettings::visibility()) {
            InboxVisibility::All => null,
            InboxVisibility::Own => $query->where('assigned_user_id', $user->id),
            InboxVisibility::OwnAndUnassigned => $query->where(
                fn (Builder $q) => $q->where('assigned_user_id', $user->id)->orWhereNull('assigned_user_id'),
            ),
        };
    }

    public function isVisibleTo(User $user): bool
    {
        return static::query()->whereKey($this->getKey())->visibleTo($user)->exists();
    }

    /**
     * @return array<string, mixed>
     */
    public function toSummary(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'channel' => $this->channel->toSummary(),
            'contact' => $this->contact->toSummary(),
            'assignee' => $this->assignee
                ? ['id' => $this->assignee->id, 'name' => $this->assignee->name]
                : null,
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'last_message_preview' => $this->last_message_preview,
            'unread_count' => $this->unread_count,
            'window_open' => $this->isWindowOpen(),
            'window_expires_at' => $this->windowExpiresAt()?->toIso8601String(),
        ];
    }
}
