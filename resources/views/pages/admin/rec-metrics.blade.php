<div class="space-y-6">
    <header>
        <h1 class="text-2xl font-bold text-[var(--color-text)]">Métricas de recomendação</h1>
        <p class="text-sm text-[var(--color-text-muted)]">
            Snapshot agregado das últimas janelas de tempo. Atualizado a cada carga de página.
        </p>
    </header>

    <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4" data-testid="widgets">
        <article
            class="rounded-[var(--radius-md)] border border-[var(--color-border)] bg-[var(--color-surface)] p-4"
            data-widget="ctr"
        >
            <h2 class="text-sm font-semibold text-[var(--color-text-muted)]">CTR (curtidas + coments + shares + views por impressão)</h2>
            <dl class="mt-3 grid grid-cols-3 gap-2 text-sm">
                <div>
                    <dt class="text-xs text-[var(--color-text-muted)]">1 hora</dt>
                    <dd class="text-lg font-semibold" data-metric="ctr_1h">{{ number_format($this->metrics['ctr']['ctr_1h'] * 100, 2) }}%</dd>
                </div>
                <div>
                    <dt class="text-xs text-[var(--color-text-muted)]">24 horas</dt>
                    <dd class="text-lg font-semibold" data-metric="ctr_24h">{{ number_format($this->metrics['ctr']['ctr_24h'] * 100, 2) }}%</dd>
                </div>
                <div>
                    <dt class="text-xs text-[var(--color-text-muted)]">7 dias</dt>
                    <dd class="text-lg font-semibold" data-metric="ctr_7d">{{ number_format($this->metrics['ctr']['ctr_7d'] * 100, 2) }}%</dd>
                </div>
            </dl>
        </article>

        <article
            class="rounded-[var(--radius-md)] border border-[var(--color-border)] bg-[var(--color-surface)] p-4"
            data-widget="dwell"
        >
            <h2 class="text-sm font-semibold text-[var(--color-text-muted)]">Dwell time mediano</h2>
            <p class="mt-3 text-2xl font-bold" data-metric="dwell_median_ms">{{ number_format($this->metrics['dwell_median_ms'], 0) }} ms</p>
            <p class="text-xs text-[var(--color-text-muted)]">Janela: últimas 24h.</p>
        </article>

        <article
            class="rounded-[var(--radius-md)] border border-[var(--color-border)] bg-[var(--color-surface)] p-4"
            data-widget="gini"
        >
            <h2 class="text-sm font-semibold text-[var(--color-text-muted)]">Gini de autores no feed</h2>
            <p class="mt-3 text-2xl font-bold" data-metric="author_gini">{{ number_format($this->metrics['author_gini'], 4) }}</p>
            <p class="text-xs text-[var(--color-text-muted)]">0 = perfeitamente uniforme · 1 = 1 autor domina.</p>
        </article>

        <article
            class="rounded-[var(--radius-md)] border border-[var(--color-border)] bg-[var(--color-surface)] p-4"
            data-widget="cluster_coverage"
        >
            <h2 class="text-sm font-semibold text-[var(--color-text-muted)]">Cobertura de clusters</h2>
            <p class="mt-3 text-2xl font-bold" data-metric="cluster_coverage">{{ number_format($this->metrics['cluster_coverage'] * 100, 2) }}%</p>
            <p class="text-xs text-[var(--color-text-muted)]">% dos clusters de interesse representados no top-20.</p>
        </article>

        <article
            class="rounded-[var(--radius-md)] border border-[var(--color-border)] bg-[var(--color-surface)] p-4"
            data-widget="negative_rates"
        >
            <h2 class="text-sm font-semibold text-[var(--color-text-muted)]">Hide / Report rate</h2>
            <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
                <div>
                    <dt class="text-xs text-[var(--color-text-muted)]">Hides</dt>
                    <dd class="text-lg font-semibold" data-metric="hide_rate">{{ number_format($this->metrics['negative_rates']['hide'] * 100, 2) }}%</dd>
                </div>
                <div>
                    <dt class="text-xs text-[var(--color-text-muted)]">Reports</dt>
                    <dd class="text-lg font-semibold" data-metric="report_rate">{{ number_format($this->metrics['negative_rates']['report'] * 100, 2) }}%</dd>
                </div>
            </dl>
        </article>

        <article
            class="rounded-[var(--radius-md)] border border-[var(--color-border)] bg-[var(--color-surface)] p-4"
            data-widget="latency"
        >
            <h2 class="text-sm font-semibold text-[var(--color-text-muted)]">Latência do feed (P50 / P95)</h2>
            <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
                <div>
                    <dt class="text-xs text-[var(--color-text-muted)]">P50</dt>
                    <dd class="text-lg font-semibold" data-metric="latency_p50">{{ number_format($this->metrics['latency']['p50'], 1) }} ms</dd>
                </div>
                <div>
                    <dt class="text-xs text-[var(--color-text-muted)]">P95</dt>
                    <dd class="text-lg font-semibold" data-metric="latency_p95">{{ number_format($this->metrics['latency']['p95'], 1) }} ms</dd>
                </div>
            </dl>
        </article>

        <article
            class="rounded-[var(--radius-md)] border border-[var(--color-border)] bg-[var(--color-surface)] p-4"
            data-widget="job_error_rate"
        >
            <h2 class="text-sm font-semibold text-[var(--color-text-muted)]">Taxa de erro de jobs</h2>
            <p class="mt-3 text-2xl font-bold" data-metric="job_error_rate">{{ number_format($this->metrics['job_error_rate'] * 100, 2) }}%</p>
            <p class="text-xs text-[var(--color-text-muted)]">Failed jobs / total recente.</p>
        </article>

        <article
            class="rounded-[var(--radius-md)] border border-[var(--color-border)] bg-[var(--color-surface)] p-4"
            data-widget="catalog_coverage"
        >
            <h2 class="text-sm font-semibold text-[var(--color-text-muted)]">Cobertura do catálogo</h2>
            <p class="mt-3 text-2xl font-bold" data-metric="catalog_coverage">{{ number_format($this->metrics['catalog_coverage'] * 100, 2) }}%</p>
            <p class="text-xs text-[var(--color-text-muted)]">% de posts impressos em ≥ 1 feed nos últimos 7 dias.</p>
        </article>
    </section>

    @if (! empty($this->metrics['variants']))
        <section
            class="rounded-[var(--radius-md)] border border-[var(--color-border)] bg-[var(--color-surface)] p-4"
            data-widget="variant_comparison"
        >
            <h2 class="text-sm font-semibold text-[var(--color-text-muted)]">Variantes (US-024)</h2>
            <table class="mt-3 w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-[var(--color-text-muted)]">
                        <th class="py-1">Variante</th>
                        <th class="py-1">Impressões</th>
                        <th class="py-1">Usuários</th>
                        <th class="py-1">CTR</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->metrics['variants'] as $variant => $data)
                        <tr class="border-t border-[var(--color-border)]" data-variant="{{ $variant }}">
                            <td class="py-1 font-medium">{{ $variant }}</td>
                            <td class="py-1">{{ $data['impressions'] }}</td>
                            <td class="py-1">{{ $data['users'] }}</td>
                            <td class="py-1">{{ number_format($data['ctr'] * 100, 2) }}%</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif
</div>
