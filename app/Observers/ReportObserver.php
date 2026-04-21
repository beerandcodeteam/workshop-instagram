<?php

namespace App\Observers;

use App\Models\Report;

class ReportObserver
{
    public function created(Report $report): void
    {
        // No-op no scaffold. Phase 6 incrementa posts.reports_count e
        // grava post_interactions (kind=report) para alimentar o avoid.
    }

    public function deleted(Report $report): void
    {
        // No-op no scaffold.
    }
}
