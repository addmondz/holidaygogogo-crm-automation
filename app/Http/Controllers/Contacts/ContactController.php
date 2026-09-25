<?php

namespace App\Http\Controllers\Contacts;

use App\Enums\ContactStatus;
use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Tag;
use App\Support\Phone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ContactController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'tag' => $request->integer('tag') ?: null,
            'status' => ContactStatus::tryFrom((string) $request->query('status'))?->value,
            'opted_out' => $request->boolean('opted_out'),
        ];

        $contacts = Contact::query()
            ->visibleTo($user)
            ->search($filters['q'])
            ->when($filters['tag'], fn (Builder $q, int $tag) => $q->whereHas('tags', fn (Builder $t) => $t->whereKey($tag)))
            ->when($filters['status'], fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters['opted_out'], fn (Builder $q) => $q->whereNotNull('opted_out_at'))
            ->with(['tags', 'conversations' => fn ($q) => $q->visibleTo($user)->with('channel', 'assignee')])
            ->latest()
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Contact $contact) => [
                ...$contact->toSummary(),
                'conversations' => $contact->conversations->map(fn (Conversation $c) => [
                    'id' => $c->id,
                    'channel' => $c->channel->toSummary(),
                    'assignee' => $c->assignee?->name,
                ]),
            ]);

        return Inertia::render('contacts/Index', [
            'contacts' => $contacts,
            'filters' => $filters,
            'tags' => Tag::query()->orderBy('name')->get()->map->toSummary(),
            'statuses' => ContactStatus::options(),
            'whatsappChannels' => Channel::query()->where('type', 'whatsapp')->where('is_active', true)->get()->map->toSummary(),
        ]);
    }

    /**
     * Add a lead by hand, e.g. someone who called the office. Admins only,
     * because agents only see contacts they are chatting with.
     */
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Contact::class);

        $tagIds = $request->validate([
            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => ['integer', Rule::exists(Tag::class, 'id')],
        ])['tag_ids'] ?? [];

        $contact = Contact::create([...$this->validated($request), 'source' => 'manual']);
        $contact->tags()->sync($tagIds);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Contact added.')]);

        return back();
    }

    public function update(Request $request, Contact $contact): RedirectResponse
    {
        Gate::authorize('update', $contact);

        $contact->update($this->validated($request, $contact));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Contact saved.')]);

        return back();
    }

    public function destroy(Contact $contact): RedirectResponse
    {
        Gate::authorize('delete', $contact);

        $contact->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Contact and their chats deleted.')]);

        return back();
    }

    /**
     * Open (or start) a WhatsApp chat with a contact, e.g. an imported lead.
     * The first message has to be an approved template.
     */
    public function startWhatsApp(Request $request, Contact $contact): RedirectResponse
    {
        Gate::authorize('update', $contact);

        $validated = $request->validate([
            'channel_id' => ['required', 'integer', Rule::exists(Channel::class, 'id')->where('type', 'whatsapp')],
        ]);

        if (! $contact->phone) {
            throw ValidationException::withMessages(['channel_id' => __('Add a phone number to this contact first.')]);
        }

        $conversation = Conversation::query()
            ->where('channel_id', $validated['channel_id'])
            ->where('contact_id', $contact->id)
            ->first()
            ?? Conversation::query()->firstOrCreate(
                ['channel_id' => $validated['channel_id'], 'external_id' => $contact->phone],
                ['contact_id' => $contact->id],
            );

        if (! $conversation->assigned_user_id && ! $request->user()->isAdmin()) {
            $conversation->update(['assigned_user_id' => $request->user()->id]);
        }

        return to_route('inbox.show', $conversation);
    }

    /**
     * Replace the contact's tags.
     */
    public function tags(Request $request, Contact $contact): RedirectResponse
    {
        Gate::authorize('update', $contact);

        $validated = $request->validate([
            'tag_ids' => ['present', 'array'],
            'tag_ids.*' => ['integer', Rule::exists(Tag::class, 'id')],
        ]);

        $contact->tags()->sync($validated['tag_ids']);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Contact $contact = null): array
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'status' => ['required', new Enum(ContactStatus::class)],
            'opted_out' => ['sometimes', 'boolean'],
        ]);

        $phone = null;

        if (filled($validated['phone'] ?? null)) {
            $phone = Phone::normalize($validated['phone']);

            if (! $phone) {
                throw ValidationException::withMessages(['phone' => __('Enter a valid phone number, e.g. +60 12-345 6789.')]);
            }

            $taken = Contact::query()->where('phone', $phone)->when($contact, fn ($q) => $q->whereKeyNot($contact->id))->exists();

            if ($taken) {
                throw ValidationException::withMessages(['phone' => __('Another contact already has this phone number.')]);
            }
        }

        $data = [
            'name' => $validated['name'] ?? null,
            'phone' => $phone,
            'email' => $validated['email'] ?? null,
            'status' => $validated['status'],
        ];

        if (array_key_exists('opted_out', $validated)) {
            $data['opted_out_at'] = $validated['opted_out'] ? ($contact?->opted_out_at ?? now()) : null;
        }

        return $data;
    }
}
