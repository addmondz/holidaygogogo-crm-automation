<?php

namespace App\Models;

use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

#[Fillable(['name', 'color', 'keywords'])]
class Tag extends Model
{
    /** @use HasFactory<TagFactory> */
    use HasFactory;

    public const COLORS = ['slate', 'red', 'orange', 'amber', 'green', 'teal', 'blue', 'indigo', 'purple', 'pink'];

    protected function casts(): array
    {
        return ['keywords' => 'array'];
    }

    /**
     * @return BelongsToMany<Contact, $this>
     */
    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class);
    }

    /**
     * Whether an incoming message should auto-apply this tag.
     */
    public function matches(string $text): bool
    {
        foreach ($this->keywords ?? [] as $keyword) {
            $keyword = trim((string) $keyword);

            if ($keyword !== '' && preg_match('/\b'.preg_quote(Str::lower($keyword), '/').'\b/u', Str::lower($text))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{id: int, name: string, color: string}
     */
    public function toSummary(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'color' => $this->color];
    }
}
