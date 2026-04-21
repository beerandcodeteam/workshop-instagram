<?php

namespace App\Http\Controllers\Recommendation;

use App\Http\Controllers\Controller;
use App\Services\Recommendation\ViewSignalRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ViewEventsController extends Controller
{
    public function store(Request $request, ViewSignalRecorder $recorder): JsonResponse
    {
        $payload = $request->validate([
            'session_id' => ['nullable', 'string', 'max:64'],
            'events' => ['required', 'array', 'min:1'],
            'events.*.post_id' => ['required', 'integer', Rule::exists('posts', 'id')],
            'events.*.duration_ms' => ['required', 'integer', 'min:0'],
            'events.*.occurred_at' => ['nullable', 'date'],
            'events.*.context' => ['nullable', 'array'],
        ]);

        $created = $recorder->record(
            user: $request->user(),
            events: $payload['events'],
            sessionId: $payload['session_id'] ?? null,
        );

        return response()->json([
            'received' => count($payload['events']),
            'recorded' => $created,
        ], 202);
    }
}
