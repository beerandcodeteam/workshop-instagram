<?php

namespace App\Services\Recommendation;

use App\Models\RecommendationExperiment;
use App\Models\User;
use Carbon\CarbonImmutable;

class ExperimentService
{
    public const RANDOM_SERVING = 'random_serving';

    public const RANKING_FORMULA_V2 = 'ranking_formula_v2';

    public const VARIANT_A = 'A';

    public const VARIANT_B = 'B';

    public const VARIANT_CONTROL = 'control';

    /**
     * Retorna a variante atribuída ao usuário para o experimento.
     *
     * Se existir atribuição persistida (e ainda válida) em
     * `recommendation_experiments`, ela prevalece. Caso contrário,
     * a variante é resolvida por hash determinístico sobre
     * (user_id + experiment_name [+ dia]).
     */
    public function variantFor(User $user, string $experiment, ?CarbonImmutable $now = null): string
    {
        $now ??= CarbonImmutable::now();

        $persisted = $this->persistedVariant($user, $experiment, $now);
        if ($persisted !== null) {
            return $persisted;
        }

        return match ($experiment) {
            self::RANDOM_SERVING => $this->randomServingVariant($user, $now),
            self::RANKING_FORMULA_V2 => $this->rankingFormulaVariant($user),
            default => self::VARIANT_A,
        };
    }

    private function randomServingVariant(User $user, CarbonImmutable $now): string
    {
        if (! (bool) config('recommendation.experiments.random_serving.enabled', true)) {
            return self::VARIANT_A;
        }

        $controlPct = (int) config('recommendation.experiments.random_serving.control_pct', 1);
        if ($controlPct <= 0) {
            return self::VARIANT_A;
        }

        $day = $now->format('Y-m-d');
        $bucket = $this->bucket(self::RANDOM_SERVING.':'.$user->id.':'.$day);

        return $bucket < $controlPct ? self::VARIANT_CONTROL : self::VARIANT_A;
    }

    private function rankingFormulaVariant(User $user): string
    {
        if (! (bool) config('recommendation.experiments.ranking_formula_v2.enabled', false)) {
            return self::VARIANT_A;
        }

        $variantBPct = (int) config('recommendation.experiments.ranking_formula_v2.variant_b_pct', 50);
        if ($variantBPct <= 0) {
            return self::VARIANT_A;
        }

        $bucket = $this->bucket(self::RANKING_FORMULA_V2.':'.$user->id);

        return $bucket < $variantBPct ? self::VARIANT_B : self::VARIANT_A;
    }

    private function persistedVariant(User $user, string $experiment, CarbonImmutable $now): ?string
    {
        $record = RecommendationExperiment::where('user_id', $user->id)
            ->where('experiment_name', $experiment)
            ->first();

        if ($record === null) {
            return null;
        }

        if ($record->expired_at !== null && $record->expired_at->lte($now)) {
            return null;
        }

        return $record->variant;
    }

    private function bucket(string $key): int
    {
        return (int) (hexdec(substr(md5($key), 0, 8)) % 100);
    }
}
