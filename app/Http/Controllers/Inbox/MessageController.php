<?php

namespace App\Http\Controllers\Inbox;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\QuickReply;
use App\Services\Inbox\MediaStore;
use App\Services\Inbox\Outbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class MessageController extends Controller
{
    /**
     * Older messages, for scrolling up in a long chat.
     */
    public function index(Request $request, Conversation $conversation): JsonResponse
    {
        Gate::authorize('view', $conversation);

        $messages = $conversation->messages()
            ->with('user')
            ->when($request->integer('before'), fn ($q, $before) => $q->where('id', '<', $before))
            ->orderByDesc('id')
            ->limit(InboxController::PAGE_SIZE + 1)
            ->get();

        return response()->json([
            'messages' => $messages->take(InboxController::PAGE_SIZE)->reverse()->values()->map->toPayload(),
            'has_older' => $messages->count() > InboxController::PAGE_SIZE,
        ]);
    }

    /**
     * Send a text, a file (with optional caption), or a quick reply with attachment.
     */
    public function store(Request $request, Conversation $conversation, Outbox $outbox): JsonResponse
    {
        Gate::authorize('reply', $conversation);

        $validated = $request->validate([
            'body' => ['nullable', 'string', 'max:4096'],
            'file' => ['nullable', 'file', 'max:'.config('crm.max_upload_kb')],
            'quick_reply_id' => ['nullable', 'integer'],
        ]);

        $conversation->load(['channel', 'contact']);
        $body = trim((string) ($validated['body'] ?? ''));

        $attachment = $request->file('file');

        if (! $attachment && ! empty($validated['quick_reply_id'])) {
            $reply = QuickReply::query()->availableTo($request->user())->find($validated['quick_reply_id']);

            // Copy the file so deleting the quick reply later keeps the chat history intact.
            if ($reply?->attachment_path) {
                $attachment = MediaStore::put(
                    MediaStore::get($reply->attachment_path),
                    $reply->attachment_mime,
                    $reply->attachment_name,
                );
            }
        }

        if (! $attachment && $body === '') {
            throw ValidationException::withMessages(['body' => __('Type a message or attach a file.')]);
        }

        $message = $attachment
            ? $outbox->sendMedia($conversation, $request->user(), $attachment, $body !== '' ? $body : null)
            : $outbox->sendText($conversation, $request->user(), $body);

        return response()->json(['message' => $message->toPayload()], 201);
    }
}
