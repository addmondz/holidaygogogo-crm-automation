<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ConversationStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AgentController extends Controller
{
    public function index(): Response
    {
        $agents = User::query()
            ->withCount([
                'assignedConversations as open_chats_count' => fn ($q) => $q->where('status', ConversationStatus::Open),
            ])
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
                'is_active' => $user->is_active,
                'is_available' => $user->is_available,
                'open_chats_count' => $user->open_chats_count,
                'last_seen_at' => $user->last_seen_at?->toIso8601String(),
            ]);

        return Inertia::render('admin/Agents', [
            'agents' => $agents,
            'roles' => array_map(fn (UserRole $role) => ['value' => $role->value, 'label' => $role->label()], UserRole::cases()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)],
            'role' => ['required', new Enum(UserRole::class)],
            'password' => ['required', 'string', Password::defaults()],
        ]);

        User::create($validated);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Agent :name added.', ['name' => $validated['name']])]);

        return back();
    }

    public function update(Request $request, User $agent): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)->ignore($agent->id)],
            'role' => ['required', new Enum(UserRole::class)],
            'is_active' => ['required', 'boolean'],
            'is_available' => ['required', 'boolean'],
            'password' => ['nullable', 'string', Password::defaults()],
        ]);

        $losesAdmin = $agent->isAdmin() && ($validated['role'] !== UserRole::Admin->value || ! $validated['is_active']);

        if ($losesAdmin && $this->isLastActiveAdmin($agent)) {
            throw ValidationException::withMessages(['role' => __('There must be at least one active admin.')]);
        }

        if (blank($validated['password'] ?? null)) {
            unset($validated['password']);
        }

        $agent->update($validated);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Agent updated.')]);

        return back();
    }

    public function destroy(Request $request, User $agent): RedirectResponse
    {
        if ($agent->is($request->user())) {
            throw ValidationException::withMessages(['agent' => __('You cannot delete your own account.')]);
        }

        if ($agent->isAdmin() && $this->isLastActiveAdmin($agent)) {
            throw ValidationException::withMessages(['agent' => __('There must be at least one active admin.')]);
        }

        // Their chats become unassigned; messages they sent are kept.
        $agent->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Agent deleted.')]);

        return back();
    }

    private function isLastActiveAdmin(User $user): bool
    {
        return ! User::query()
            ->where('role', UserRole::Admin)
            ->where('is_active', true)
            ->whereKeyNot($user->id)
            ->exists();
    }
}
