<?php

namespace App\Http\Controllers\Inbox;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\WhatsappTemplate;
use App\Services\Inbox\Outbox;
use App\Services\Inbox\TemplateRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class TemplateMessageController extends Controller
{
    public function store(Request $request, Conversation $conversation, Outbox $outbox, TemplateRenderer $renderer): JsonResponse
    {
        Gate::authorize('reply', $conversation);

        $validated = $request->validate([
            'template_id' => ['required', 'integer'],
            'values' => ['array'],
            'values.*' => ['nullable', 'string', 'max:1024'],
        ]);

        $conversation->load(['channel', 'contact']);

        $template = WhatsappTemplate::query()
            ->where('channel_id', $conversation->channel_id)
            ->where('status', 'APPROVED')
            ->find($validated['template_id']);

        if (! $template) {
            throw ValidationException::withMessages(['template_id' => __('Choose an approved template for this WhatsApp number.')]);
        }

        $values = $validated['values'] ?? [];

        if ($missing = $renderer->missing($template, $values)) {
            throw ValidationException::withMessages(['values' => __('Fill in every variable (:keys).', ['keys' => implode(', ', $missing)])]);
        }

        $message = $outbox->sendTemplate($conversation, $request->user(), $template, $values);

        return response()->json(['message' => $message->toPayload()], 201);
    }
}
