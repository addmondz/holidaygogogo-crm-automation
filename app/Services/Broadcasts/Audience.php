<?php

namespace App\Services\Broadcasts;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who receives a blast: contacts matching the chosen tags and lead
 * statuses, minus anyone who opted out.
 *
 * WhatsApp: any contact with a phone number (templates can be sent any time).
 * Messenger: only people who messaged the Page in the last 24 hours, because
 * Meta doesn't allow promotional messages outside that window.
 */
class Audience
{
    /**
     * @param  array{tag_ids?: list<int>, exclude_tag_ids?: list<int>, statuses?: list<string>}  $audience
     * @return Builder<Contact>
     */
    public static function query(Channel $channel, array $audience): Builder
    {
        $tagIds = array_values(array_filter($audience['tag_ids'] ?? []));
        $excludeTagIds = array_values(array_filter($audience['exclude_tag_ids'] ?? []));
        $statuses = array_values(array_filter($audience['statuses'] ?? []));

        return Contact::query()
            ->whereNull('opted_out_at')
            ->when($channel->isWhatsApp(), fn (Builder $q) => $q->whereNotNull('phone'))
            ->when($channel->isMessenger(), fn (Builder $q) => $q->whereHas(
                'conversations',
                fn (Builder $c) => $c->where('channel_id', $channel->id)
                    ->where('last_inbound_at', '>', now()->subHours(Conversation::SERVICE_WINDOW_HOURS)),
            ))
            ->when($tagIds, fn (Builder $q) => $q->whereHas('tags', fn (Builder $t) => $t->whereIn('tags.id', $tagIds)))
            ->when($excludeTagIds, fn (Builder $q) => $q->whereDoesntHave('tags', fn (Builder $t) => $t->whereIn('tags.id', $excludeTagIds)))
            ->when($statuses, fn (Builder $q) => $q->whereIn('status', $statuses));
    }
}
