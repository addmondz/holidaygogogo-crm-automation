<?php

namespace App\Http\Controllers;

use App\Models\QuickReply;
use App\Services\Inbox\MediaStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Saved replies agents insert by typing "/" in the chat box. Admins manage
 * shared replies; every agent can keep personal ones.
 */
class QuickReplyController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('quick-replies/Index', [
            'quickReplies' => QuickReply::query()
                ->availableTo($user)
                ->with('user')
                ->orderByDesc('is_shared')
                ->orderBy('shortcut')
                ->get()
                ->map(fn (QuickReply $reply) => $reply->toPayload($user)),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $reply = new QuickReply([...$data, 'user_id' => $request->user()->id]);
        $this->storeAttachment($request, $reply);
        $reply->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Quick reply saved.')]);

        return back();
    }

    public function update(Request $request, QuickReply $quickReply): RedirectResponse
    {
        abort_unless($quickReply->canBeManagedBy($request->user()), 403);

        $quickReply->fill($this->validated($request));

        if ($request->boolean('remove_attachment')) {
            $this->deleteAttachment($quickReply);
        }

        $this->storeAttachment($request, $quickReply);
        $quickReply->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Quick reply saved.')]);

        return back();
    }

    public function destroy(Request $request, QuickReply $quickReply): RedirectResponse
    {
        abort_unless($quickReply->canBeManagedBy($request->user()), 403);

        $this->deleteAttachment($quickReply);
        $quickReply->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Quick reply deleted.')]);

        return back();
    }

    public function attachment(Request $request, QuickReply $quickReply): StreamedResponse
    {
        abort_unless($quickReply->is_shared || $quickReply->user_id === $request->user()->id, 403);
        abort_unless($quickReply->attachment_path && MediaStore::disk()->exists($quickReply->attachment_path), 404);

        return MediaStore::disk()->response(
            $quickReply->attachment_path,
            $quickReply->attachment_name,
            ['Content-Type' => $quickReply->attachment_mime ?? 'application/octet-stream'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        // Agents often type the shortcut with the slash they use in chat.
        $request->merge(['shortcut' => ltrim(trim((string) $request->input('shortcut')), '/')]);

        $validated = $request->validate([
            'shortcut' => ['required', 'string', 'max:50', 'regex:/^[\pL\pN_-]+$/u'],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:4096'],
            'is_shared' => ['sometimes', 'boolean'],
            'attachment' => ['nullable', 'file', 'max:'.config('crm.max_upload_kb')],
        ], [
            'shortcut.regex' => __('Use letters, numbers, - or _ only (no spaces).'),
        ]);

        return [
            'shortcut' => Str::lower($validated['shortcut']),
            'title' => $validated['title'],
            'body' => $validated['body'],
            // Only admins can share replies with the whole team.
            'is_shared' => $request->user()->isAdmin() && ($validated['is_shared'] ?? false),
        ];
    }

    private function storeAttachment(Request $request, QuickReply $reply): void
    {
        if (! $request->hasFile('attachment')) {
            return;
        }

        $this->deleteAttachment($reply);

        $stored = MediaStore::putUpload($request->file('attachment'), 'quick-replies');

        $reply->fill([
            'attachment_path' => $stored['path'],
            'attachment_name' => $stored['filename'],
            'attachment_mime' => $stored['mime'],
        ]);
    }

    private function deleteAttachment(QuickReply $reply): void
    {
        if ($reply->attachment_path) {
            MediaStore::disk()->delete($reply->attachment_path);
        }

        $reply->fill(['attachment_path' => null, 'attachment_name' => null, 'attachment_mime' => null]);
    }
}
