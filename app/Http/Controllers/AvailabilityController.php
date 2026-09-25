<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AvailabilityController extends Controller
{
    /**
     * Agents switch themselves on/off for automatic lead assignment.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        $validated = $request->validate(['is_available' => ['required', 'boolean']]);

        $request->user()->update($validated);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $validated['is_available']
                ? __('You will receive new leads automatically.')
                : __('You will not receive new leads automatically.'),
        ]);

        return back();
    }
}
