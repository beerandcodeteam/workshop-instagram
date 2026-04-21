## Phase 8 — Tooling de operador

**Histórias:** US-018, US-019, US-020, US-022 (complementar), US-023.

### 8.1 `config/recommendation.php` completo (US-020)
- [ ] **8.1.1** Grupos: `weights` (defaults espelhando §6 overview), `half_life`, `score` (alpha, beta, gamma, delta, epsilon), `mmr` (lambda, pool_size), `quota` (top_k, per_author), `cold_start.threshold`, `moderation.reports_threshold`, `trending.window_hours`.
- [ ] **8.1.2** (Opcional) Tabela `recommendation_settings` para override runtime + `RecommendationSettingsService` cacheado 1min.
- **Pest tests (`tests/Feature/Rec/RecommendationConfigTest.php`):**
    - [ ] `score_formula_reads_weights_from_config`.
    - [ ] `changing_config_takes_effect_without_restart` (resolve container novo).

### 8.2 Dashboard de métricas (US-018)
- [ ] **8.2.1** Página Livewire `pages::admin.rec-metrics` (rota `/admin/rec/metrics`, protegida por gate `admin`).
- [ ] **8.2.2** Widgets: CTR (1h/24h/7d), dwell mediano, Gini de autores, cluster coverage, hide/report rate, latência P50/P95, erro rate de jobs, cobertura de catálogo.
- [ ] **8.2.3** Comparação variante tratamento vs controle quando US-024 ativa.
- **Pest tests (`tests/Feature/Rec/MetricsDashboardTest.php`):**
    - [ ] `non_admin_gets_403`.
    - [ ] `admin_sees_all_widgets`.
    - [ ] `widgets_compute_from_recent_ranking_logs_and_interactions`.

### 8.3 `rec:healthcheck` + alertas (US-019)
- [ ] **8.3.1** Comando consulta `failed_jobs`, lag de fila, taxa de erro recente.
- [ ] **8.3.2** `Schedule::command(...)->everyFiveMinutes()`.
- [ ] **8.3.3** Se threshold estourado → log WARNING + (opcional) Slack via `services.slack` já configurado.
- **Pest tests (`tests/Feature/Rec/HealthcheckCommandTest.php`):**
    - [ ] `command_detects_embedding_job_error_rate_over_5_percent`.
    - [ ] `command_detects_realtime_queue_lag_over_60s`.
    - [ ] `command_deduplicates_same_alert_within_same_day`.

### 8.4 Kill-switch (US-023)
- [ ] **8.4.1** `rec:disable {--reason=}` e `rec:enable` — gravam em `cache` / `settings` o estado.
- [ ] **8.4.2** `RecommendationService::feedFor` checa flag logo no início; quando off, retorna `latest('posts.created_at')` direto.
- **Pest tests (`tests/Feature/Rec/KillSwitchTest.php`):**
    - [ ] `rec_disable_falls_back_to_chronological_feed`.
    - [ ] `rec_disable_requires_reason`.
    - [ ] `rec_enable_restores_recommendation_pipeline`.

---

