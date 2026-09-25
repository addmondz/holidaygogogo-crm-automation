<?php

namespace App\Http\Controllers;

use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $today = now()->startOfDay();

        $open = fn (): Builder => Conversation::query()->visibleTo($user)->where('status', ConversationStatus::Open);

        $stats = [
            'open' => $open()->count(),
            'unassigned' => $open()->whereNull('assigned_user_id')->count(),
            'mine' => $open()->where('assigned_user_id', $user->id)->count(),
            'needs_reply' => $open()->whereColumn('last_inbound_at', '>=', 'last_message_at')->count(),
            'new_leads_today' => Contact::query()->visibleTo($user)->where('created_at', '>=', $today)->count(),
            'messages_in_today' => $this->messagesToday($user, MessageDirection::Inbound),
            'messages_out_today' => $this->messagesToday($user, MessageDirection::Outbound),
        ];

        $workload = $user->isAdmin()
            ? User::query()
                ->active()
                ->withCount([
                    'assignedConversations as open_count' => fn ($q) => $q->where('status', ConversationStatus::Open),
                    'assignedConversations as needs_reply_count' => fn ($q) => $q
                        ->where('status', ConversationStatus::Open)
                        ->whereColumn('last_inbound_at', '>=', 'last_message_at'),
                ])
                ->orderByDesc('open_count')
                ->get()
                ->map(fn (User $agent) => [
                    'id' => $agent->id,
                    'name' => $agent->name,
                    'is_available' => $agent->is_available,
                    'open_count' => $agent->open_count,
                    'needs_reply_count' => $agent->needs_reply_count,
                ])
            : [];

        return Inertia::render('Dashboard', [
            'stats' => $stats,
            'workload' => $workload,
        ]);
    }

    private function messagesToday(User $user, MessageDirection $direction): int
    {
        return Message::query()
            ->where('direction', $direction)
            ->where('created_at', '>=', now()->startOfDay())
            ->whereHas('conversation', fn (Builder $q) => $q->visibleTo($user))
            ->count();
    }
}
