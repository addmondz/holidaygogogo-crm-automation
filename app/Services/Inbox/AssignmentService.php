<?php

namespace App\Services\Inbox;

use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Events\AgentNotified;
use App\Events\ConversationChanged;
use App\Events\MessageSaved;
use App\Models\Conversation;
use App\Models\User;
use App\Support\CrmSettings;
use App\Support\Realtime;
use Illuminate\Support\Facades\DB;

class AssignmentService
{
    /**
     * Assign (or unassign with null) a chat, log it in the chat timeline and
     * notify the new agent.
     */
    public function assign(Conversation $conversation, ?User $agent, ?User $by = null, bool $automatic = false): void
    {
        if ($conversation->assigned_user_id === $agent?->id) {
            return;
        }

        $conversation->update(['assigned_user_id' => $agent?->id]);
        $conversation->setRelation('assignee', $agent);

        $text = match (true) {
            $agent === null => 'Chat unassigned'.($by ? " by {$by->name}" : ''),
            $automatic => "Auto-assigned to {$agent->name}",
            $by?->is($agent) => "{$agent->name} took this chat",
            default => "Assigned to {$agent->name}".($by ? " by {$by->name}" : ''),
        };

        $event = $conversation->messages()->create([
            'direction' => MessageDirection::Event,
            'type' => MessageType::Event,
            'body' => $text,
            'status' => MessageStatus::Received,
            'user_id' => $by?->id,
        ]);

        Realtime::send(new MessageSaved($event));
        Realtime::send(new ConversationChanged($conversation));

        if ($agent && ! $agent->is($by)) {
            $contact = $conversation->contact()->first();

            Realtime::send(new AgentNotified(
                $agent,
                'New chat assigned to you',
                $contact?->display_name ?? 'A customer',
                $conversation->id,
            ));
        }
    }

    /**
     * Round-robin: give the chat to the active, available agent who has waited
     * longest since their last automatic assignment.
     */
    public function autoAssign(Conversation $conversation): ?User
    {
        if (! CrmSettings::autoAssign() || $conversation->assigned_user_id) {
            return null;
        }

        $agent = DB::transaction(function () {
            $agent = User::query()
                ->active()
                ->where('is_available', true)
                ->orderByRaw('last_assigned_at IS NOT NULL')
                ->orderBy('last_assigned_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            $agent?->forceFill(['last_assigned_at' => now()])->save();

            return $agent;
        });

        if ($agent) {
            $this->assign($conversation, $agent, automatic: true);
        }

        return $agent;
    }
}
