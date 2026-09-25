<?php

namespace App\Models;

use Database\Factories\WhatsappTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['channel_id', 'meta_id', 'name', 'language', 'category', 'status', 'parameter_format', 'components'])]
class WhatsappTemplate extends Model
{
    /** @use HasFactory<WhatsappTemplateFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['components' => 'array'];
    }

    /**
     * @return BelongsTo<Channel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function isApproved(): bool
    {
        return strtoupper((string) $this->status) === 'APPROVED';
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'channel_id' => $this->channel_id,
            'name' => $this->name,
            'language' => $this->language,
            'category' => $this->category,
            'status' => $this->status,
            'parameter_format' => $this->parameter_format,
            'components' => $this->components ?? [],
        ];
    }
}
