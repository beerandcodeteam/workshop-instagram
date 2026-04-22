<?php

use App\Jobs\AggregateViewSignalsJob;
use App\Jobs\PurgeRecommendationLogsJob;
use App\Jobs\RefreshInterestClustersJob;
use App\Jobs\RefreshLongTermEmbeddingsJob;
use App\Jobs\RefreshTrendingPoolJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new RefreshLongTermEmbeddingsJob)
    ->dailyAt('03:00')
    ->timezone('America/Sao_Paulo')
    ->name('refresh-long-term-embeddings')
    ->withoutOverlapping();

Schedule::job(new AggregateViewSignalsJob)
    ->everyTenMinutes()
    ->name('aggregate-view-signals')
    ->withoutOverlapping();

Schedule::job(new RefreshTrendingPoolJob)
    ->everyFiveMinutes()
    ->name('refresh-trending-pool')
    ->withoutOverlapping();

Schedule::job(new PurgeRecommendationLogsJob)
    ->dailyAt('04:00')
    ->timezone('America/Sao_Paulo')
    ->name('purge-recommendation-logs')
    ->withoutOverlapping();

Schedule::job(new RefreshInterestClustersJob)
    ->weekly()
    ->sundays()
    ->at('02:00')
    ->timezone('America/Sao_Paulo')
    ->name('refresh-interest-clusters')
    ->withoutOverlapping();

Schedule::command('rec:healthcheck')
    ->everyFiveMinutes()
    ->name('rec-healthcheck')
    ->withoutOverlapping();

Schedule::command('app:purge-old-events')
    ->weekly()
    ->sundays()
    ->at('05:00')
    ->timezone('America/Sao_Paulo')
    ->name('purge-old-events')
    ->withoutOverlapping();
