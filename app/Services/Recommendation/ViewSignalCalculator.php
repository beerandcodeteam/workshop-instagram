<?php

namespace App\Services\Recommendation;

class ViewSignalCalculator
{
    public const KIND_SKIP_FAST = 'skip_fast';

    public const KIND_VIEW = 'view';

    /**
     * Mapeia dwell em ms para (kind, weight) conforme overview §5.4.
     *
     *  < 1000ms       -> skip_fast (peso -0.3)
     *  1000–3000ms    -> neutro (não grava)
     *  3000–10000ms   -> view (0.2 .. 0.5 linear)
     *  10000–30000ms  -> view (0.5 .. 0.8 linear)
     *  > 30000ms      -> view 1.0 (cap)
     *
     * @return null|array{kind: string, weight: float}
     */
    public static function classify(int $durationMs): ?array
    {
        if ($durationMs < 0) {
            return null;
        }

        if ($durationMs < 1000) {
            return ['kind' => self::KIND_SKIP_FAST, 'weight' => -0.3];
        }

        if ($durationMs < 3000) {
            return null;
        }

        $weight = match (true) {
            $durationMs <= 10000 => 0.2 + (($durationMs - 3000) / 7000.0) * 0.3,
            $durationMs <= 30000 => 0.5 + (($durationMs - 10000) / 20000.0) * 0.3,
            default => 1.0,
        };

        return [
            'kind' => self::KIND_VIEW,
            'weight' => round($weight, 3),
        ];
    }
}
