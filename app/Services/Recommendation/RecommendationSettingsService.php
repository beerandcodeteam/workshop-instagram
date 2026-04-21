<?php

namespace App\Services\Recommendation;

use App\Models\RecommendationSetting;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

class RecommendationSettingsService
{
    public const CACHE_KEY = 'rec:settings:overrides';

    public const CACHE_TTL_SECONDS = 60;

    /**
     * Resolve uma chave de configuração (ex.: `score.alpha`) considerando
     * overrides em `recommendation_settings`, com fallback em `config()`.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $overrides = $this->overrides();

        $override = Arr::get($overrides, $key);
        if ($override !== null) {
            return $override;
        }

        return Config::get('recommendation.'.$key, $default);
    }

    /**
     * Persiste um override e refresca o cache imediatamente.
     */
    public function set(string $key, mixed $value, ?string $updatedBy = null): void
    {
        RecommendationSetting::updateOrCreate(
            ['key' => $key],
            ['value' => ['v' => $value], 'updated_by' => $updatedBy],
        );

        $this->forget();
    }

    /**
     * Remove um override.
     */
    public function forgetOverride(string $key): void
    {
        RecommendationSetting::where('key', $key)->delete();
        $this->forget();
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, mixed>
     */
    private function overrides(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function (): array {
            $rows = RecommendationSetting::all(['key', 'value']);

            $overrides = [];

            foreach ($rows as $row) {
                $value = $row->value;

                if (is_array($value) && array_key_exists('v', $value)) {
                    $value = $value['v'];
                }

                Arr::set($overrides, $row->key, $value);
            }

            return $overrides;
        });
    }
}
