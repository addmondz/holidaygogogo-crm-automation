<?php

namespace App\Models;

use App\Enums\ChannelType;
use Database\Factories\ChannelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['type', 'name', 'external_id', 'business_account_id', 'display_phone', 'access_token', 'is_active'])]
#[Hidden(['access_token'])]
class Channel extends Model
{
    /** @use HasFactory<ChannelFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => ChannelType::class,
            'access_token' => 'encrypted',
            'is_active' => 'boolean',
        ];
    }

    public function isWhatsApp(): bool
    {
        return $this->type === ChannelType::WhatsApp;
    }

    public function isMessenger(): bool
    {
        return $this->type === ChannelType::Messenger;
    }

    /**
     * @return HasMany<Conversation, $this>
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /**
     * @return HasMany<WhatsappTemplate, $this>
     */
    public function templates(): HasMany
    {
        return $this->hasMany(WhatsappTemplate::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toSummary(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'name' => $this->name,
            'display_phone' => $this->display_phone,
        ];
    }
}
