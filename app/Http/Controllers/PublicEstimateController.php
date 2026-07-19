<?php

namespace App\Http\Controllers;

use App\Models\Estimate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PublicEstimateController extends Controller
{
    public function show(string $token): View
    {
        $estimate = Estimate::where('client_access_token', $token)->where('is_current', true)->with('items')->firstOrFail();
        if (! $estimate->client_viewed_at) $estimate->update(['client_viewed_at' => now(), 'status' => Estimate::STATUS_PENDING]);
        return view('estimates.public', compact('estimate', 'token'));
    }

    public function respond(Request $request, string $token): RedirectResponse
    {
        $estimate = Estimate::where('client_access_token', $token)->where('is_current', true)->firstOrFail();
        $validated = $request->validate(['response' => ['required', 'in:accept,reject'], 'note' => ['nullable', 'string', 'max:2000']]);
        $estimate->update([
            'status' => $validated['response'] === 'accept' ? Estimate::STATUS_ACCEPTED : Estimate::STATUS_REJECTED,
            'ordered_on' => $validated['response'] === 'accept' ? now()->toDateString() : null,
            'lost_reason' => $validated['response'] === 'reject' ? ($validated['note'] ?? null) : null,
            'response_note' => $validated['note'] ?? null, 'responded_at' => now(),
        ]);
        return back()->with('status', $validated['response'] === 'accept' ? '承認しました。' : '辞退の回答を送信しました。');
    }
}
