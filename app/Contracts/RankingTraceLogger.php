<?php

namespace App\Contracts;

interface RankingTraceLogger
{
    /**
     * Emit a structured ranking-trace event.
     *
     * Context MUST include the keys:
     *  - request_id:    UUID gerado no início do feedFor()
     *  - user_id:       int
     *  - phase:         candidate_gen | ranking | mmr | quota | response
     *  - source:        recommendation_source slug (when applicable)
     *  - post_id:       int (when applicable)
     *  - scores:        array<string, float> com partial + final
     *  - rank_position: int (when applicable)
     *
     * @param  array<string, mixed>  $context
     */
    public function trace(string $event, array $context): void;
}
