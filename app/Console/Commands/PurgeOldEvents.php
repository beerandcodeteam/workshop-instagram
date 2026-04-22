<?php

namespace App\Console\Commands;

use App\Models\PostInteraction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('app:purge-old-events {--days=365 : Idade mínima (dias) das interações a remover}')]
#[Description('Remove eventos antigos da tabela post_interactions (housekeeping semanal).')]
class PurgeOldEvents extends Command
{
    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);

        $deleted = PostInteraction::where('created_at', '<', $cutoff)->delete();

        $message = sprintf(
            'Removidas %d interações anteriores a %s.',
            $deleted,
            $cutoff->toDateTimeString(),
        );

        $this->info($message);

        Log::channel('recommendation')->info('rec.housekeeping.purge_old_events', [
            'deleted' => $deleted,
            'cutoff' => $cutoff->toIso8601String(),
            'days' => $days,
        ]);

        return self::SUCCESS;
    }
}
