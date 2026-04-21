<?php

namespace App\Services\Recommendation;

use App\Models\EmbeddingModel;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class UserEmbeddingService
{
    public function refreshLongTerm(User $user): void
    {
        $windowDays = (int) config('recommendation.long_term.window_days', 180);
        $halfLifeDays = (float) config('recommendation.long_term.half_life_days', 30);
        $threshold = (float) config('recommendation.long_term.weight_threshold', 2.0);

        $rows = $this->loadInteractionRows(
            userId: $user->id,
            since: now()->subDays($windowDays),
            isPositive: true,
        );

        [$vector, $totalWeight] = $this->aggregate($rows, $halfLifeDays * 86400.0);

        if ($vector === null || $totalWeight < $threshold) {
            $user->forceFill([
                'long_term_embedding' => null,
                'long_term_embedding_updated_at' => now(),
                'long_term_embedding_model_id' => null,
            ])->save();

            return;
        }

        $user->forceFill([
            'long_term_embedding' => $vector,
            'long_term_embedding_updated_at' => now(),
            'long_term_embedding_model_id' => $this->currentModelId(),
        ])->save();
    }

    public function refreshShortTerm(User $user): void
    {
        $windowHours = (int) config('recommendation.short_term.window_hours', 48);
        $halfLifeHours = (float) config('recommendation.short_term.half_life_hours', 6);
        $threshold = (float) config('recommendation.short_term.weight_threshold', 1.0);
        $cacheTtl = (int) config('recommendation.short_term.cache_ttl_seconds', 3600);

        $rows = $this->loadInteractionRows(
            userId: $user->id,
            since: now()->subHours($windowHours),
            isPositive: true,
        );

        [$vector, $totalWeight] = $this->aggregate($rows, $halfLifeHours * 3600.0);

        $cacheKey = "rec:user:{$user->id}:short_term";

        if ($vector === null || $totalWeight < $threshold) {
            $user->forceFill([
                'short_term_embedding' => null,
                'short_term_embedding_updated_at' => now(),
                'short_term_embedding_model_id' => null,
            ])->save();

            Redis::del($cacheKey);

            return;
        }

        $user->forceFill([
            'short_term_embedding' => $vector,
            'short_term_embedding_updated_at' => now(),
            'short_term_embedding_model_id' => $this->currentModelId(),
        ])->save();

        Redis::setex($cacheKey, $cacheTtl, json_encode($vector));
    }

    public function refreshAvoid(User $user): void
    {
        $windowDays = (int) config('recommendation.avoid.window_days', 90);
        $threshold = (float) config('recommendation.avoid.weight_threshold', 1.0);

        $rows = $this->loadInteractionRows(
            userId: $user->id,
            since: now()->subDays($windowDays),
            isPositive: false,
        );

        [$vector, $totalWeight] = $this->aggregatePerTypeHalfLife($rows);

        if ($vector === null || $totalWeight < $threshold) {
            $user->forceFill([
                'avoid_embedding' => null,
                'avoid_embedding_updated_at' => now(),
                'avoid_embedding_model_id' => null,
            ])->save();

            return;
        }

        $user->forceFill([
            'avoid_embedding' => $vector,
            'avoid_embedding_updated_at' => now(),
            'avoid_embedding_model_id' => $this->currentModelId(),
        ])->save();
    }

    public function readShortTerm(User $user): ?array
    {
        $cacheKey = "rec:user:{$user->id}:short_term";
        $cached = Redis::get($cacheKey);

        if ($cached !== null && $cached !== false) {
            $decoded = json_decode($cached, true);

            if (is_array($decoded) && $decoded !== []) {
                return $decoded;
            }
        }

        $vector = $user->fresh()?->short_term_embedding;

        if (! is_array($vector) || $vector === []) {
            return null;
        }

        $cacheTtl = (int) config('recommendation.short_term.cache_ttl_seconds', 3600);
        Redis::setex($cacheKey, $cacheTtl, json_encode($vector));

        return $vector;
    }

    /**
     * @return Collection<int, object>
     */
    private function loadInteractionRows(int $userId, Carbon $since, bool $isPositive): Collection
    {
        return DB::table('post_interactions as pi')
            ->join('interaction_types as it', 'it.id', '=', 'pi.interaction_type_id')
            ->join('posts as p', 'p.id', '=', 'pi.post_id')
            ->where('pi.user_id', $userId)
            ->where('pi.created_at', '>=', $since)
            ->where('it.is_positive', $isPositive)
            ->whereNotNull('p.embedding')
            ->select(
                'pi.weight as weight',
                'pi.created_at as created_at',
                'it.half_life_hours as half_life_hours',
                DB::raw('p.embedding::text as embedding_text'),
            )
            ->get();
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return array{0: ?list<float>, 1: float}
     */
    private function aggregate(Collection $rows, float $halfLifeSeconds): array
    {
        if ($rows->isEmpty()) {
            return [null, 0.0];
        }

        $now = now()->getTimestamp();
        $totalWeight = 0.0;
        $sum = null;
        $ln2 = log(2);

        foreach ($rows as $row) {
            $createdAt = Carbon::parse($row->created_at)->getTimestamp();
            $ageSeconds = max(0, $now - $createdAt);
            $weight = (float) $row->weight * exp(-$ln2 * $ageSeconds / $halfLifeSeconds);

            if ($weight === 0.0) {
                continue;
            }

            $vector = $this->parseVector($row->embedding_text);

            if ($sum === null) {
                $sum = array_fill(0, count($vector), 0.0);
            }

            foreach ($vector as $i => $value) {
                $sum[$i] += $value * $weight;
            }

            $totalWeight += $weight;
        }

        if ($sum === null || $totalWeight <= 0.0) {
            return [null, 0.0];
        }

        $mean = array_map(fn ($v) => $v / $totalWeight, $sum);

        return [$this->l2Normalize($mean), $totalWeight];
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return array{0: ?list<float>, 1: float}
     */
    private function aggregatePerTypeHalfLife(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [null, 0.0];
        }

        $now = now()->getTimestamp();
        $totalWeight = 0.0;
        $sum = null;
        $ln2 = log(2);

        foreach ($rows as $row) {
            $halfLifeSeconds = max(1, (int) $row->half_life_hours) * 3600.0;
            $createdAt = Carbon::parse($row->created_at)->getTimestamp();
            $ageSeconds = max(0, $now - $createdAt);
            $base = abs((float) $row->weight);

            if ($base === 0.0) {
                continue;
            }

            $weight = $base * exp(-$ln2 * $ageSeconds / $halfLifeSeconds);

            if ($weight === 0.0) {
                continue;
            }

            $vector = $this->parseVector($row->embedding_text);

            if ($sum === null) {
                $sum = array_fill(0, count($vector), 0.0);
            }

            foreach ($vector as $i => $value) {
                $sum[$i] += $value * $weight;
            }

            $totalWeight += $weight;
        }

        if ($sum === null || $totalWeight <= 0.0) {
            return [null, 0.0];
        }

        $mean = array_map(fn ($v) => $v / $totalWeight, $sum);

        return [$this->l2Normalize($mean), $totalWeight];
    }

    /**
     * @return list<float>
     */
    private function parseVector(string $text): array
    {
        $trimmed = trim($text, "[] \t\n\r");

        if ($trimmed === '') {
            return [];
        }

        return array_map('floatval', explode(',', $trimmed));
    }

    /**
     * @param  list<float>  $vector
     * @return list<float>
     */
    private function l2Normalize(array $vector): array
    {
        $magnitude = sqrt(array_sum(array_map(static fn (float $v) => $v * $v, $vector)));

        if ($magnitude <= 0.0) {
            return $vector;
        }

        return array_map(static fn (float $v) => $v / $magnitude, $vector);
    }

    private function currentModelId(): ?int
    {
        return EmbeddingModel::where('slug', config('services.gemini.embedding.model'))->value('id');
    }
}
