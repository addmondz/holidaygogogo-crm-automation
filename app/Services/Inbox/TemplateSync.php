<?php

namespace App\Services\Inbox;

use App\Models\Channel;
use App\Models\WhatsappTemplate;
use App\Services\Meta\WhatsAppClient;

/**
 * Copies the WhatsApp message templates (created and approved in WhatsApp
 * Manager) into the CRM so agents can send them.
 */
class TemplateSync
{
    /**
     * @return int Number of templates found.
     */
    public function sync(Channel $channel): int
    {
        $templates = (new WhatsAppClient($channel))->templates();
        $seen = [];

        foreach ($templates as $template) {
            $record = WhatsappTemplate::query()->updateOrCreate(
                ['channel_id' => $channel->id, 'name' => $template['name'], 'language' => $template['language']],
                [
                    'meta_id' => $template['id'] ?? null,
                    'category' => $template['category'] ?? null,
                    'status' => $template['status'] ?? null,
                    'parameter_format' => $template['parameter_format'] ?? 'POSITIONAL',
                    'components' => $template['components'] ?? [],
                ],
            );

            $seen[] = $record->id;
        }

        // Templates deleted in WhatsApp Manager can no longer be sent.
        WhatsappTemplate::query()
            ->where('channel_id', $channel->id)
            ->whereNotIn('id', $seen)
            ->update(['status' => 'DELETED']);

        return count($templates);
    }
}
