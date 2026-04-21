<?php

namespace App\Services\Recommendation;

use App\Models\User;

class UserEmbeddingService
{
    public function refreshLongTerm(User $user): void
    {
        // TODO Phase 3: agregação ponderada com decay 30d a partir de
        // post_interactions positivas dos últimos 90-180d.
    }

    public function refreshShortTerm(User $user): void
    {
        // TODO Phase 3: média ponderada com decay 6h a partir de
        // post_interactions das últimas 24-48h, com cache em Redis.
    }

    public function refreshAvoid(User $user): void
    {
        // TODO Phase 3: agregação de hide/report/skip_fast como vetor
        // de penalidade.
    }
}
