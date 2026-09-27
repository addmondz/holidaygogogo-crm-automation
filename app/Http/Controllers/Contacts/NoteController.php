<?php

namespace App\Http\Controllers\Contacts;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Note;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Internal notes on a lead, visible to the team only (never sent to the customer).
 */
class NoteController extends Controller
{
    public function store(Request $request, Contact $contact): RedirectResponse
    {
        Gate::authorize('update', $contact);

        $validated = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        $contact->notes()->create([...$validated, 'user_id' => $request->user()->id]);

        return back();
    }

    public function destroy(Request $request, Note $note): RedirectResponse
    {
        abort_unless($request->user()->isAdmin() || $note->user_id === $request->user()->id, 403);

        $note->delete();

        return back();
    }
}
