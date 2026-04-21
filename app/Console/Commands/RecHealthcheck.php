<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

#[Signature('rec:healthcheck')]
#[Description('Verifica saúde do pipeline de recomendação (jobs, fila, taxa de erro) e emite alertas.')]
class RecHealthcheck extends Command
{
    public function handle(): int
    {
        $alerts = [];

        $embeddingErrorRate = $this->embeddingJobErrorRate();
        $embeddingThreshold = (float) config('recommendation.healthcheck.embedding_error_rate_threshold', 5.0);

        if ($embeddingErrorRate > $embeddingThreshold) {
            $alerts[] = [
                'key' => 'embedding_error_rate',
                'message' => sprintf(
                    'Taxa de erro do GeneratePostEmbeddingJob em %.2f%% (threshold %.2f%%)',
                    $embeddingErrorRate,
                    $embeddingThreshold,
                ),
                'context' => ['error_rate_pct' => $embeddingErrorRate],
            ];
        }

        $realtimeLagSeconds = $this->realtimeQueueLagSeconds();
        $lagThreshold = (int) config('recommendation.healthcheck.realtime_queue_lag_seconds', 60);

        if ($realtimeLagSeconds > $lagThreshold) {
            $alerts[] = [
                'key' => 'realtime_queue_lag',
                'message' => sprintf(
                    'Lag da fila realtime em %ds (threshold %ds)',
                    $realtimeLagSeconds,
                    $lagThreshold,
                ),
                'context' => ['lag_seconds' => $realtimeLagSeconds],
            ];
        }

        if ($alerts === []) {
            $this->info('Pipeline saudável.');
            $this->line(sprintf(
                'embedding_error_rate=%.2f%%  realtime_lag=%ds',
                $embeddingErrorRate,
                $realtimeLagSeconds,
            ));

            return self::SUCCESS;
        }

        foreach ($alerts as $alert) {
            if (! $this->shouldAlert($alert['key'])) {
                continue;
            }

            $this->warn($alert['message']);
            Log::channel('recommendation')->warning('rec.healthcheck.alert', [
                'alert' => $alert['key'],
                'message' => $alert['message'],
                'context' => $alert['context'],
            ]);

            $this->maybeNotifySlack($alert);

            $this->markAlertSent($alert['key']);
        }

        return self::FAILURE;
    }

    private function embeddingJobErrorRate(): float
    {
        $windowMinutes = (int) config('recommendation.healthcheck.window_minutes', 15);
        $since = now()->subMinutes($windowMinutes);

        $failed = DB::table('failed_jobs')
            ->where('failed_at', '>=', $since)
            ->where('payload', 'like', '%GeneratePostEmbeddingJob%')
            ->count();

        $pending = DB::table('jobs')
            ->where('queue', 'embeddings')
            ->where('created_at', '>=', $since->getTimestamp())
            ->count();

        $total = $failed + $pending;

        if ($total === 0) {
            return 0.0;
        }

        return round(($failed / $total) * 100, 2);
    }

    private function realtimeQueueLagSeconds(): int
    {
        $oldest = DB::table('jobs')
            ->where('queue', 'realtime')
            ->whereNull('reserved_at')
            ->orderBy('available_at')
            ->value('available_at');

        if ($oldest === null) {
            return 0;
        }

        return max(0, time() - (int) $oldest);
    }

    private function shouldAlert(string $key): bool
    {
        $cacheKey = $this->dedupCacheKey($key);

        return ! Cache::has($cacheKey);
    }

    private function markAlertSent(string $key): void
    {
        $cacheKey = $this->dedupCacheKey($key);

        Cache::put($cacheKey, true, now()->endOfDay());
    }

    private function dedupCacheKey(string $key): string
    {
        $prefix = (string) config('recommendation.healthcheck.alert_dedup_prefix', 'rec:health:alert');

        return sprintf('%s:%s:%s', $prefix, $key, now()->format('Y-m-d'));
    }

    /**
     * @param  array{key: string, message: string, context: array<string, mixed>}  $alert
     */
    private function maybeNotifySlack(array $alert): void
    {
        $webhook = config('logging.channels.slack.url');

        if (empty($webhook)) {
            return;
        }

        try {
            Log::channel('slack')->warning($alert['message'], $alert['context']);
        } catch (\Throwable $e) {
            Log::channel('recommendation')->warning('rec.healthcheck.slack_failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
