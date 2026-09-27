<?php

namespace App\Http\Controllers\Inbox;

use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Events\ConversationChanged;
use App\Events\MessageSaved;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Inbox\AssignmentService;
use App\Support\Realtime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Inertia\Inertia;

class ConversationController extends Controller
{
    public function assign(Request $request, Conversation $conversation, AssignmentService $assignments): RedirectResponse
    {
        Gate::authorize('assign', $conversation);

        $validated = $request->validate([
            'user_id' => ['nullable', 'integer', Rule::exists(User::class, 'id')->where('is_active', true)],
        ]);

        $agent = isset($validated['user_id']) ? User::find($validated['user_id']) : null;

        $assignments->assign($conversation, $agent, $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $agent ? __('Chat assigned to :name.', ['name' => $agent->name]) : __('Chat unassigned.'),
        ]);

        return back();
    }

    /**
     * Close a chat when the enquiry is handled; it reopens when the customer writes again.
     */
    public function status(Request $request, Conversation $conversation): RedirectResponse
    {
        Gate::authorize('reply', $conversation);

        $validated = $request->validate(['status' => ['required', new Enum(ConversationStatus::class)]]);
        $status = ConversationStatus::from($validated['status']);

        if ($conversation->status !== $status) {
            $conversation->update(['status' => $status]);

            $event = $conversation->messages()->create([
                'direction' => MessageDirection::Event,
                'type' => MessageType::Event,
                'body' => ($status === ConversationStatus::Closed ? 'Chat closed by ' : 'Chat reopened by ').$request->user()->name,
                'status' => MessageStatus::Received,
                'user_id' => $request->user()->id,
            ]);

            Realtime::send(new MessageSaved($event));
            Realtime::send(new ConversationChanged($conversation));
        }

        return back();
    }
}
