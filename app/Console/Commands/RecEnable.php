<?php

namespace App\Console\Commands;

use App\Services\Recommendation\KillSwitchService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('rec:enable')]
#[Description('Liga novamente o pipeline de recomendação após `rec:disable`.')]
class RecEnable extends Command
{
    public function handle(KillSwitchService $killSwitch): int
    {
        $previous = $killSwitch->status();

        $killSwitch->enable();

        Log::channel('recommendation')->info('rec.kill_switch.enabled', [
            'previous_state' => $previous,
        ]);

        $this->info('Pipeline de recomendação reativado.');

        return self::SUCCESS;
    }
}
