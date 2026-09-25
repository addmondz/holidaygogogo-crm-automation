<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Services\Inbox\MediaStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves chat photos and files to agents who can see the chat.
 */
class MediaController extends Controller
{
    public function __invoke(Request $request, Message $message): StreamedResponse
    {
        Gate::authorize('view', $message->conversation);

        $path = $message->media['path'] ?? null;

        abort_unless($path && MediaStore::disk()->exists($path), 404);

        $filename = $message->media['filename'] ?? basename($path);
        $headers = ['Content-Type' => $message->media['mime'] ?? 'application/octet-stream', 'Cache-Control' => 'private, max-age=86400'];

        return $request->boolean('download')
            ? MediaStore::disk()->download($path, $filename, $headers)
            : MediaStore::disk()->response($path, $filename, $headers);
    }
}
