<?php

namespace App\Models;

use App\Enums\ContactStatus;
use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['name', 'phone', 'email', 'status', 'source', 'avatar_url', 'opted_out_at'])]
class Contact extends Model
{
    /** @use HasFactory<ContactFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => ContactStatus::class,
            'opted_out_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->orderBy('name');
    }

    /**
     * @return HasMany<Conversation, $this>
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /**
     * @return HasMany<Note, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(Note::class)->latest();
    }

    /**
     * @return Attribute<string, never>
     */
    protected function displayName(): Attribute
    {
        return Attribute::get(fn () => $this->name ?: ($this->phone ? '+'.$this->phone : 'Unknown'));
    }

    /**
     * @return Attribute<string, never>
     */
    protected function firstName(): Attribute
    {
        return Attribute::get(fn () => $this->name ? Str::before(trim($this->name), ' ') : '');
    }

    public function isOptedOut(): bool
    {
        return $this->opted_out_at !== null;
    }

    /**
     * Contacts that belong to at least one conversation the user can see.
     * Admins can see every contact, including imported ones with no chat yet.
     *
     * @param  Builder<Contact>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->isAdmin()) {
            return;
        }

        $query->whereHas('conversations', fn (Builder $q) => $q->visibleTo($user));
    }

    /**
     * @param  Builder<Contact>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $digits = preg_replace('/\D/', '', $term);

        $query->where(function (Builder $q) use ($term, $digits) {
            $q->where('name', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%");

            if ($digits !== '') {
                $q->orWhere('phone', 'like', "%{$digits}%");
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function toSummary(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'display_name' => $this->display_name,
            'phone' => $this->phone,
            'email' => $this->email,
            'status' => $this->status->value,
            'source' => $this->source,
            'avatar_url' => $this->avatar_url,
            'opted_out' => $this->isOptedOut(),
            'tags' => $this->relationLoaded('tags')
                ? $this->tags->map->toSummary()->values()->all()
                : [],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
