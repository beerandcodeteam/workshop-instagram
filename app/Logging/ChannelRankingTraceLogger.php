<?php

namespace App\Logging;

use App\Contracts\RankingTraceLogger;
use Illuminate\Log\LogManager;

class ChannelRankingTraceLogger implements RankingTraceLogger
{
    public function __construct(protected LogManager $logManager) {}

    public function trace(string $event, array $context): void
    {
        $this->logManager->channel('recommendation')->info($event, $context);
    }
}
