<?php

namespace App\Console\Commands;

use App\Services\Recommendation\KillSwitchService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('rec:disable {--reason= : Motivo do desligamento (obrigatório)} {--by= : Operador responsável}')]
#[Description('Desliga o pipeline de recomendação. O feed cai para ordenação cronológica até `rec:enable`.')]
class RecDisable extends Command
{
    public function handle(KillSwitchService $killSwitch): int
    {
        $reason = (string) ($this->option('reason') ?? '');
        $reason = trim($reason);

        if ($reason === '') {
            $this->error('A opção --reason é obrigatória. Ex.: rec:disable --reason="incidente em curso".');

            return self::INVALID;
        }

        $by = $this->option('by');

        $killSwitch->disable($reason, $by !== null ? (string) $by : null);

        Log::channel('recommendation')->warning('rec.kill_switch.disabled', [
            'reason' => $reason,
            'disabled_by' => $by,
        ]);

        $this->warn('Pipeline de recomendação desligado.');
        $this->line("Motivo: {$reason}");

        return self::SUCCESS;
    }
}
