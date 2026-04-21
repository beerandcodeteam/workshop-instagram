<?php

namespace App\Console\Commands;

use App\Models\RecommendationLog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('rec:trace {user_id : ID do usuário} {post_id : ID do post} {--request= : Filtrar por request_id específico} {--negative : Mostra o motivo caso o post tenha sido filtrado}')]
#[Description('Inspeciona os traces de ranking para um par (usuário, post).')]
class RecTrace extends Command
{
    public function handle(): int
    {
        $userId = (int) $this->argument('user_id');
        $postId = (int) $this->argument('post_id');
        $requestId = $this->option('request');
        $negative = (bool) $this->option('negative');

        $query = RecommendationLog::query()
            ->with('source')
            ->where('user_id', $userId)
            ->where('post_id', $postId);

        if ($requestId !== null) {
            $query->where('request_id', $requestId);
        }

        if ($negative) {
            $query->whereNotNull('filtered_reason');
        }

        $traces = $query->orderByDesc('created_at')->get();

        if ($traces->isEmpty()) {
            $this->warn("Nenhum trace encontrado para user={$userId}, post={$postId}.");
            $this->line('Traces são mantidos por 7 dias — talvez já tenham sido purgados.');

            return self::SUCCESS;
        }

        $headers = ['Request', 'Criado em', 'Fonte', 'Score', 'Posição', 'Motivo filtro', 'Breakdown'];

        $rows = $traces->map(function (RecommendationLog $trace): array {
            $breakdown = $this->formatBreakdown($trace->scores_breakdown);

            return [
                (string) $trace->request_id,
                $trace->created_at?->format('Y-m-d H:i:s') ?? '-',
                $trace->source?->slug ?? '-',
                number_format((float) $trace->score, 4),
                (int) $trace->rank_position < 0 ? '-' : (string) $trace->rank_position,
                $trace->filtered_reason ?? '-',
                $breakdown,
            ];
        })->all();

        $this->table($headers, $rows);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>|null  $breakdown
     */
    private function formatBreakdown(?array $breakdown): string
    {
        if ($breakdown === null || $breakdown === []) {
            return '-';
        }

        $pairs = [];
        foreach ($breakdown as $key => $value) {
            if (is_float($value) || is_int($value)) {
                $pairs[] = "{$key}=".number_format((float) $value, 4);
            } elseif (is_bool($value)) {
                $pairs[] = "{$key}=".($value ? 'true' : 'false');
            } else {
                $pairs[] = "{$key}={$value}";
            }
        }

        return implode(' ', $pairs);
    }
}
