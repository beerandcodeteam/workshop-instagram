<?php

namespace App\Services\Recommendation;

use Illuminate\Support\Facades\Cache;

class KillSwitchService
{
    /**
     * @return array{disabled: bool, reason: string|null, disabled_at: string|null, disabled_by: string|null}|null
     */
    public function status(): ?array
    {
        return Cache::get($this->cacheKey());
    }

    public function isDisabled(): bool
    {
        $status = $this->status();

        return is_array($status) && ($status['disabled'] ?? false);
    }

    public function disable(string $reason, ?string $disabledBy = null): void
    {
        Cache::put(
            $this->cacheKey(),
            [
                'disabled' => true,
                'reason' => $reason,
                'disabled_at' => now()->toIso8601String(),
                'disabled_by' => $disabledBy,
            ],
            (int) config('recommendation.kill_switch.cache_ttl_seconds', 86400 * 7),
        );
    }

    public function enable(): void
    {
        Cache::forget($this->cacheKey());
    }

    private function cacheKey(): string
    {
        return (string) config('recommendation.kill_switch.cache_key', 'rec:kill_switch');
    }
}
