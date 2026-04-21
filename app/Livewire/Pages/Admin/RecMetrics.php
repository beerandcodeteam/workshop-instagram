<?php

namespace App\Livewire\Pages\Admin;

use App\Services\Recommendation\MetricsService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

#[Layout('layouts.app')]
#[Title('Métricas de recomendação')]
class RecMetrics extends Component
{
    public function mount(): void
    {
        if (! auth()->check() || ! Gate::forUser(auth()->user())->allows('admin')) {
            throw new AccessDeniedHttpException('Acesso restrito a administradores.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function metrics(): array
    {
        $service = app(MetricsService::class);

        return [
            'ctr' => $service->ctrBuckets(),
            'dwell_median_ms' => $service->dwellMedianMs(),
            'author_gini' => $service->authorGini(),
            'cluster_coverage' => $service->clusterCoverage(),
            'negative_rates' => $service->negativeRates(),
            'latency' => $service->feedLatencyPercentiles(),
            'job_error_rate' => $service->jobErrorRate(),
            'catalog_coverage' => $service->catalogCoverage(),
            'variants' => $service->variantComparison(),
        ];
    }

    public function render()
    {
        return view('pages.admin.rec-metrics');
    }
}
