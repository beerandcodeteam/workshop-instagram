<?php

namespace App\Services\Recommendation;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class Ranker
{
    /**
     * Build a score for a single post given the user's embeddings.
     *
     * @param  list<float>  $postEmbedding
     * @return array{0: float, 1: array<string, float>}
     */
    public function score(array $postEmbedding, User $user, ?float $alphaOverride = null, float $sourceTrendingScore = 0.0, float $recencyBoost = 0.0, float $contextBoost = 0.0, string $variant = ExperimentService::VARIANT_A): array
    {
        $alpha = $alphaOverride ?? $this->resolveAlpha($user);
        $coefficients = $this->coefficients($variant);
        $beta = $coefficients['beta'];
        $gamma = $coefficients['gamma'];
        $delta = $coefficients['delta'];
        $epsilon = $coefficients['epsilon'];

        $lt = $this->safeVector($user->long_term_embedding);
        $st = $this->safeVector($user->short_term_embedding);
        $av = $this->safeVector($user->avoid_embedding);

        $cosLt = $lt === null ? null : $this->cosine($postEmbedding, $lt);
        $cosSt = $st === null ? null : $this->cosine($postEmbedding, $st);
        $cosAv = $av === null ? 0.0 : max(0.0, $this->cosine($postEmbedding, $av));

        // Fallback quando um dos vetores é null.
        if ($cosLt === null && $cosSt === null) {
            $similarity = 0.0;
            $effectiveAlpha = $alpha;
        } elseif ($cosLt === null) {
            $similarity = $cosSt;
            $effectiveAlpha = 0.0;
        } elseif ($cosSt === null) {
            $similarity = $cosLt;
            $effectiveAlpha = 1.0;
        } else {
            $similarity = $alpha * $cosLt + (1.0 - $alpha) * $cosSt;
            $effectiveAlpha = $alpha;
        }

        $score = $similarity
            - $beta * $cosAv
            + $gamma * $recencyBoost
            + $delta * $sourceTrendingScore
            + $epsilon * $contextBoost;

        $breakdown = [
            'cos_lt' => $cosLt ?? 0.0,
            'cos_st' => $cosSt ?? 0.0,
            'cos_av' => $cosAv,
            'alpha' => $effectiveAlpha,
            'similarity' => $similarity,
            'recency_boost' => $recencyBoost,
            'trending_boost' => $sourceTrendingScore,
            'context_boost' => $contextBoost,
            'variant' => $variant,
            'beta' => $beta,
            'gamma' => $gamma,
            'delta' => $delta,
            'epsilon' => $epsilon,
            'final' => $score,
        ];

        return [$score, $breakdown];
    }

    /**
     * Rank a set of candidates. Returns an array of RankedCandidate ordered by score desc.
     *
     * @param  array<int, Candidate>  $candidates  indexed by post_id
     * @return list<RankedCandidate>
     */
    public function rank(array $candidates, User $user, ?float $alphaOverride = null, string $variant = ExperimentService::VARIANT_A): array
    {
        if ($candidates === []) {
            return [];
        }

        $postIds = array_keys($candidates);

        $rows = DB::table('posts')
            ->select([
                'id',
                'user_id',
                'created_at',
                DB::raw('embedding::text as embedding_text'),
            ])
            ->whereIn('id', $postIds)
            ->whereNotNull('embedding')
            ->get()
            ->keyBy('id');

        $alpha = $alphaOverride ?? $this->resolveAlpha($user);

        $nowTs = now()->getTimestamp();
        $halfLifeHours = (float) config('recommendation.score.recency_half_life_hours', 6);
        $ln2 = log(2);

        $maxTrendingScore = 0.0;
        foreach ($candidates as $candidate) {
            if ($candidate->source === 'trending' && $candidate->sourceScore > $maxTrendingScore) {
                $maxTrendingScore = $candidate->sourceScore;
            }
        }

        $ranked = [];
        foreach ($candidates as $postId => $candidate) {
            $row = $rows[$postId] ?? null;
            if ($row === null) {
                continue;
            }

            $embedding = $this->parseVector((string) $row->embedding_text);
            if ($embedding === []) {
                continue;
            }

            $ageHours = max(0, $nowTs - strtotime($row->created_at)) / 3600.0;
            $recencyBoost = exp(-$ln2 * $ageHours / $halfLifeHours);

            $trendingBoost = 0.0;
            if ($candidate->source === 'trending' && $maxTrendingScore > 0) {
                $trendingBoost = $candidate->sourceScore / $maxTrendingScore;
            }

            $contextBoost = 0.0;

            [$score, $breakdown] = $this->score(
                postEmbedding: $embedding,
                user: $user,
                alphaOverride: $alpha,
                sourceTrendingScore: $trendingBoost,
                recencyBoost: $recencyBoost,
                contextBoost: $contextBoost,
                variant: $variant,
            );

            $breakdown['source'] = $candidate->source;
            $breakdown['source_score'] = $candidate->sourceScore;

            $ranked[] = new RankedCandidate(
                candidate: $candidate,
                authorId: (int) $row->user_id,
                score: $score,
                scoresBreakdown: $breakdown,
                embedding: $embedding,
            );
        }

        usort($ranked, static fn (RankedCandidate $a, RankedCandidate $b) => $b->score <=> $a->score);

        return $ranked;
    }

    public function resolveAlpha(User $user): float
    {
        $default = (float) config('recommendation.score.alpha_default', 0.8);
        $activeAlpha = (float) config('recommendation.score.alpha_active_session', 0.3);
        $threshold = (int) config('recommendation.score.alpha_active_threshold', 5);

        $recentInteractions = DB::table('post_interactions')
            ->where('user_id', $user->id)
            ->where('created_at', '>=', now()->subHours(24))
            ->count();

        return $recentInteractions >= $threshold ? $activeAlpha : $default;
    }

    public function recencyBoost(\DateTimeInterface|string $createdAt): float
    {
        $ts = $createdAt instanceof \DateTimeInterface ? $createdAt->getTimestamp() : strtotime($createdAt);
        $ageHours = max(0, now()->getTimestamp() - $ts) / 3600.0;
        $halfLifeHours = (float) config('recommendation.score.recency_half_life_hours', 6);

        return exp(-log(2) * $ageHours / $halfLifeHours);
    }

    /**
     * @return array{beta: float, gamma: float, delta: float, epsilon: float}
     */
    private function coefficients(string $variant): array
    {
        $coefficients = [
            'beta' => (float) config('recommendation.score.beta_avoid', 0.5),
            'gamma' => (float) config('recommendation.score.gamma_recency', 0.15),
            'delta' => (float) config('recommendation.score.delta_trending', 0.1),
            'epsilon' => (float) config('recommendation.score.epsilon_context', 0.05),
        ];

        if ($variant !== ExperimentService::VARIANT_B) {
            return $coefficients;
        }

        $overrides = (array) config('recommendation.experiments.ranking_formula_v2.variant_b', []);

        foreach (['beta_avoid' => 'beta', 'gamma_recency' => 'gamma', 'delta_trending' => 'delta', 'epsilon_context' => 'epsilon'] as $configKey => $localKey) {
            if (array_key_exists($configKey, $overrides)) {
                $coefficients[$localKey] = (float) $overrides[$configKey];
            }
        }

        return $coefficients;
    }

    /**
     * @return list<float>|null
     */
    private function safeVector(mixed $value): ?array
    {
        if (! is_array($value) || $value === []) {
            return null;
        }

        return array_values(array_map('floatval', $value));
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private function cosine(array $a, array $b): float
    {
        $count = min(count($a), count($b));
        if ($count === 0) {
            return 0.0;
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0; $i < $count; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        $denominator = sqrt($normA) * sqrt($normB);
        if ($denominator <= 0.0) {
            return 0.0;
        }

        return $dot / $denominator;
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
}
