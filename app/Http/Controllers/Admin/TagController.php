<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TagController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/Tags', [
            'tags' => Tag::query()
                ->withCount('contacts')
                ->orderBy('name')
                ->get()
                ->map(fn (Tag $tag) => [
                    ...$tag->toSummary(),
                    'keywords' => $tag->keywords ?? [],
                    'contacts_count' => $tag->contacts_count,
                ]),
            'colors' => Tag::COLORS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Tag::create($this->validated($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Tag created.')]);

        return back();
    }

    public function update(Request $request, Tag $tag): RedirectResponse
    {
        $tag->update($this->validated($request, $tag));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Tag saved.')]);

        return back();
    }

    public function destroy(Tag $tag): RedirectResponse
    {
        $tag->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Tag deleted.')]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Tag $tag = null): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:50', Rule::unique(Tag::class)->ignore($tag?->id)],
            'color' => ['required', Rule::in(Tag::COLORS)],
            'keywords' => ['nullable', 'string', 'max:1000'],
        ]);

        $validated['keywords'] = array_values(array_unique(array_filter(array_map(
            'trim',
            explode(',', (string) ($validated['keywords'] ?? '')),
        ))));

        return $validated;
    }
}
