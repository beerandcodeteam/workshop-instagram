<?php

namespace App\Services\Recommendation;

use App\Models\InteractionType;
use App\Models\PostInteraction;
use App\Models\User;
use Illuminate\Support\Carbon;

class ViewSignalRecorder
{
    /**
     * Persiste eventos de dwell time como linhas em `post_interactions`,
     * descartando os que caem na faixa neutra (1–3s) conforme overview §5.4.
     *
     * @param  array<int, array{post_id: int, duration_ms: int, occurred_at?: ?string, context?: ?array<string, mixed>}>  $events
     * @return int Quantidade de linhas criadas.
     */
    public function record(User $user, array $events, ?string $sessionId = null): int
    {
        $typeIdsBySlug = InteractionType::query()
            ->whereIn('slug', [ViewSignalCalculator::KIND_VIEW, ViewSignalCalculator::KIND_SKIP_FAST])
            ->pluck('id', 'slug');

        $created = 0;

        foreach ($events as $event) {
            $duration = (int) ($event['duration_ms'] ?? 0);
            $classification = ViewSignalCalculator::classify($duration);

            if ($classification === null) {
                continue;
            }

            $typeId = $typeIdsBySlug[$classification['kind']] ?? null;

            if ($typeId === null) {
                continue;
            }

            $occurredAt = isset($event['occurred_at']) && $event['occurred_at'] !== null
                ? Carbon::parse($event['occurred_at'])
                : now();

            PostInteraction::create([
                'user_id' => $user->id,
                'post_id' => (int) $event['post_id'],
                'interaction_type_id' => $typeId,
                'weight' => $classification['weight'],
                'session_id' => $sessionId,
                'duration_ms' => $duration,
                'context' => $event['context'] ?? null,
                'created_at' => $occurredAt,
            ]);

            $created++;
        }

        return $created;
    }
}
