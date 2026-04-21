<?php

use App\Jobs\RefreshLongTermEmbeddingsJob;
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
