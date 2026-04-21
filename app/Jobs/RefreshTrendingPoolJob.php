<?php

namespace App\Jobs;

use App\Services\Recommendation\TrendingPoolService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RefreshTrendingPoolJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct()
    {
        $this->onQueue('realtime');
    }

    public function handle(TrendingPoolService $service): void
    {
        $service->refresh();
    }
}
