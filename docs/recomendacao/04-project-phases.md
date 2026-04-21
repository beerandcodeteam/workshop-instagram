# Sistema de Recomendação — Project Phases

Este documento quebra o trabalho de construção do sistema de recomendação em **fases numeradas** para referência posterior (ex.: *"implementar Phase 5.3"*). Cada sub-fase aponta a história de usuário que motiva, os arquivos/tabelas afetados e os **testes Pest** que servem de critério de aceitação.

Fontes:
- `01-overview.md` (arquitetura alvo)
- `02-user-stories.md` (US-001 a US-034)
- `03-database-schema.md` (DBML alvo)

Convenções:
- **[x]** — já implementado no código atual (ver §Audit at the bottom).
- **[ ]** — pendente.
- **[~]** — existe no código mas em forma que precisa ser refatorada para atingir o alvo.
- Testes Pest ficam em `tests/Feature/` (`tests/Feature/Rec/` para este projeto). Livewire usa `Livewire::test()`. Todos os testes que tocam DB usam `RefreshDatabase`.
- Tasks referenciam histórias assim: **(US-011, US-022)**.
- **Phase 0 não tem testes automatizados** (smoke checks manuais conforme instrução).

---

## Phase 7 — Observabilidade de ranking e debug

**Histórias:** US-009, US-016, US-017.

### 7.1 `recommendation_logs` + trace logger (US-017)
- [ ] **7.1.1** Migration `recommendation_logs` conforme schema doc (request_id UUID, scores_breakdown jsonb).
- [ ] **7.1.2** Model `RecommendationLog` + relações.
- [ ] **7.1.3** `PersistRankingTracesJob` na fila `traces` — escreve em batch ao fim do `feedFor`.
- [ ] **7.1.4** `PurgeRecommendationLogsJob` — `Schedule::dailyAt('04:00')` deleta onde `created_at < now() - 7 days`.
- **Pest tests (`tests/Feature/Rec/RankingTraceLoggerTest.php`):**
    - [ ] `feed_request_emits_n_traces`.
    - [ ] `trace_includes_source_score_and_final_position`.
    - [ ] `traces_older_than_7_days_are_purged`.
    - [ ] `filtered_candidate_is_logged_with_filtered_reason`.

### 7.2 Comando `rec:trace` (US-016)
- [ ] **7.2.1** `rec:trace {user_id} {post_id} [--request=ID]` — lê `recommendation_logs`, formata em tabela ASCII.
- [ ] **7.2.2** Suporte a flag `--negative` que mostra motivo de filtro se post NÃO foi recomendado.
- **Pest tests (`tests/Feature/Rec/RecTraceCommandTest.php`):**
    - [ ] `command_outputs_scores_and_position_for_user_post_pair`.
    - [ ] `command_shows_filtered_reason_for_excluded_candidates`.
    - [ ] `command_handles_expired_trace_gracefully` (>7d).

### 7.3 "Por que vi isso?" (US-009, Could)
- [ ] **7.3.1** Ação em menu do `post.card` que abre modal com explicação humanizada baseada em `recommendation_logs.recommendation_source_id`.
- [ ] **7.3.2** Mapa slug → frase em `config/recommendation.php` (ex.: `ann_short_term` → "parecido com o que você curtiu nas últimas horas").
- **Pest tests (`tests/Feature/Rec/WhyDidISeeThisTest.php`):**
    - [ ] `modal_renders_human_readable_reason`.
    - [ ] `modal_handles_missing_trace_gracefully` (trace purgado): mostra "Não temos mais essa informação".

---

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

## Phase 9 — A/B testing + random serving

**Histórias:** US-021, US-024.

### 9.1 `recommendation_experiments` + assignment
- [ ] **9.1.1** Migration `recommendation_experiments` conforme schema doc.
- [ ] **9.1.2** `ExperimentService::variantFor(User $user, string $experiment): string` — hash determinístico (ou lê DB).
- **Pest tests (`tests/Feature/Rec/ExperimentAssignmentTest.php`):**
    - [ ] `same_user_gets_same_variant_within_window`.
    - [ ] `variants_distribute_roughly_uniformly`.

### 9.2 Random serving 1% (US-024)
- [ ] **9.2.1** Checagem no início do `feedFor` — 1% dos usuários (rotação diária por `hash(user_id + day) % 100 < control_pct`) recebe feed cronológico.
- [ ] **9.2.2** `recommendation_logs.experiment_variant = 'control'` para esse grupo.
- **Pest tests (`tests/Feature/Rec/RandomServingTest.php`):**
    - [ ] `control_group_receives_chronological_feed`.
    - [ ] `control_assignment_rotates_daily`.
    - [ ] `approximately_1_percent_of_users_assigned_to_control` (tolerance).

### 9.3 Variantes de ranking (US-021)
- [ ] **9.3.1** Config `recommendation.experiments['ranking_formula_v2']` → fórmula alternativa no `Ranker`.
- [ ] **9.3.2** Métricas quebradas por variante no dashboard (US-018).
- **Pest tests (`tests/Feature/Rec/RankingVariantTest.php`):**
    - [ ] `variant_b_uses_alternative_scoring_formula`.
    - [ ] `ranking_logs_record_variant_served`.

---

## Phase 10 — Interest clusters (nível avançado)

**Histórias:** US-033, US-006 (parte de cluster coverage).

### 10.1 Schema `user_interest_clusters`
- [ ] **10.1.1** Migration + model `UserInterestCluster`.
- [ ] **10.1.2** HNSW em `user_interest_clusters.embedding`.
- **Pest tests (`tests/Feature/Rec/InterestClustersSchemaTest.php`):**
    - [ ] `table_and_hnsw_index_exist`.

### 10.2 `RefreshInterestClustersJob` (US-033)
- [ ] **10.2.1** k-means em PHP puro (lento mas dentro do workshop é OK) com silhouette score para escolher k ∈ [3..7].
- [ ] **10.2.2** Entrada: embeddings de posts com sinais positivos dos últimos 90d.
- [ ] **10.2.3** Apenas roda se `count >= 30`.
- [ ] **10.2.4** `Schedule::job(...)->weekly()` + trigger on `count_delta > 20`.
- **Pest tests (`tests/Feature/Rec/RefreshInterestClustersJobTest.php`):**
    - [ ] `job_creates_3_to_7_clusters_per_eligible_user`.
    - [ ] `job_skips_users_below_interaction_threshold`.
    - [ ] `cluster_weights_sum_to_1`.
    - [ ] `sample_count_is_populated`.
    - [ ] `replaces_not_updates_existing_rows` (delete + insert, não update in-place).

### 10.3 `CandidateGenerator::annByClusters`
- [ ] **10.3.1** Para cada cluster do user, pega top-100 posts por `embedding <=> cluster.embedding`.
- [ ] **10.3.2** Limit global: no máximo 300 do conjunto de clusters.
- **Pest tests (`tests/Feature/Rec/AnnByClustersTest.php`):**
    - [ ] `generator_returns_candidates_from_each_cluster_proportional_to_weight`.
    - [ ] `generator_noops_if_user_has_no_clusters`.

### 10.4 Cluster coverage no feed (US-006)
- [ ] **10.4.1** MMR estendido ou regra pós-quota que garante ≥70% dos clusters ativos no top-20.
- **Pest tests (`tests/Feature/Rec/ClusterCoverageTest.php`):**
    - [ ] `top_20_represents_at_least_70_percent_of_user_clusters` (para usuários com ≥3 clusters).

---

## Phase 11 — Housekeeping

**Histórias:** US-034.

### 11.1 Purge de eventos antigos (US-034)
- [ ] **11.1.1** `app:purge-old-events` → deleta `post_interactions` com `created_at < now() - 1 year`.
- [ ] **11.1.2** `Schedule::command(...)->weekly()`.
- **Pest tests (`tests/Feature/Rec/PurgeOldEventsTest.php`):**
    - [ ] `purges_interactions_older_than_1_year`.
    - [ ] `preserves_recent_interactions`.

### 11.2 Arch tests
- [ ] **11.2.1** Pest arch: `app/Services/Recommendation` não pode ser acessado de Blade (somente via Livewire/Controller).
- [ ] **11.2.2** Jobs da camada rec não podem usar `auth()` (parâmetro explícito).
- [ ] **11.2.3** Models da rec não têm lógica de ranking (Ranker é separado).
- **Pest tests (`tests/Feature/Rec/ArchTest.php`):**
    - [ ] arch testes Pest 4 (`arch()->expect(...)`).

### 11.3 Smoke de ponta-a-ponta
- [ ] **11.3.1** Teste narrativo: cadastra user, cria post (embedding gera), user interage (5 likes), ST atualiza, feed retorna ranked, hide um post, ele some, reporta outro, some para todos após N reports, operador consulta `rec:trace`.
- **Pest tests (`tests/Feature/Rec/EndToEndSmokeTest.php`):**
    - [ ] `full_happy_path_completes_without_errors`.

---

## §7.1 Ordem sugerida de migrations

Migrations novas — **criar na seguinte ordem**, respeitando dependências FK:

| # | Nome (timestamp + slug)                                                  | Cria                                                                           | Fase |
|---|--------------------------------------------------------------------------|--------------------------------------------------------------------------------|------|
| 1 | `YYYY_MM_DD_HHMMSS_create_embedding_models_table`                        | Lookup `embedding_models`.                                                     | 0.3  |
| 2 | `..._create_interaction_types_table`                                     | Lookup `interaction_types`.                                                    | 0.3  |
| 3 | `..._create_recommendation_sources_table`                                | Lookup `recommendation_sources`.                                               | 0.3  |
| 4 | `..._create_media_types_table`                                           | Lookup `media_types`.                                                          | 0.3  |
| 5 | `..._add_media_type_id_to_post_media`                                    | FK em `post_media`.                                                            | 0.3  |
| 6 | `..._create_post_interactions_table`                                     | Fato append-only + indexes.                                                    | 1.1  |
| 7 | `..._add_embedding_columns_to_posts`                                     | Vetor + metadata + reports_count + soft delete em `posts`.                     | 2.1  |
| 8 | `..._add_hnsw_index_to_posts_embedding`                                  | HNSW via DB::statement.                                                        | 2.1  |
| 9 | `..._backfill_posts_embedding_from_post_embeddings`                      | Data migration (não drop).                                                     | 2.1  |
| 10 | `..._rename_users_embedding_to_long_term_and_add_siblings`               | Rename + 2 novos vetores + metadados + HNSWs.                                  | 3.1  |
| 11 | `..._create_reports_table`                                              | Moderação.                                                                     | 6.2  |
| 12 | `..._create_user_interest_clusters_table`                               | Multi-vetor + HNSW.                                                            | 10.1 |
| 13 | `..._create_recommendation_logs_table`                                  | Traces.                                                                        | 7.1  |
| 14 | `..._create_recommendation_experiments_table`                           | A/B.                                                                           | 9.1  |
| 15 | `..._drop_post_embeddings_table`                                        | **Última**; só depois de rodar Phase 2.2 em estável.                           | 2.1  |

Cada migration:
- Tem `up()` e `down()` reversíveis.
- Usa `DB::statement(...)` para `CREATE INDEX ... USING hnsw` (Blueprint não suporta).
- Usa `CREATE EXTENSION IF NOT EXISTS vector` quando aplicável (idempotente).
- Inclui comentário no topo do arquivo com a fase e a história motivadora (ex.: `// Phase 2.1 — US-011, US-022`).

---

## §7.2 Audit do estado atual (justifica os `[x]` acima)

Verificado no código em `2026-04-21`:

| Item                                                                             | Status |
|----------------------------------------------------------------------------------|--------|
| `vector` extension habilitada via `Schema::ensureVectorExtensionExists()`        | [x]    |
| `post_embeddings.embedding vector(1536)` com HNSW `vector_cosine_ops`            | [x]    |
| `users.embedding vector(1536) null`                                              | [x] (renomear na Phase 3.1) |
| `GeminiEmbeddingService::embed()` funcionando, multimodal, 1536d                 | [x]    |
| `GeneratePostEmbeddingJob` grava em `post_embeddings`                            | [x] (refatorar em 2.2) |
| `CalculateUserCentroidJob` calcula média simples                                 | [x] (deprecar em 3.2) |
| `PostObserver::created` → `dispatch_sync(GeneratePostEmbeddingJob)`              | [x] (async em 2.2) |
| `LikeObserver::created/deleted` → `CalculateUserCentroidJob`                     | [x] (extender em 1.2) |
| `App\Console\Commands\GeneratePostEmbeddings` (backfill)                         | [x] (estender em 2.4) |
| Feed ordena por cosseno quando user tem embedding                                | [x] (substituir em 5.8) |
| `config/services.php gemini.*`                                                   | [x]    |
| `laravel/ai ^0.6` instalado                                                      | [x] (não usado; decisão pendente §11.4 do overview) |
| Fila `database`, sem Horizon                                                     | [ ] (Phase 0.6) |
| Redis configurado mas não usado como cache/queue                                 | [ ] (Phase 0.6) |
| `post_interactions`, `reports`, `user_interest_clusters`, `recommendation_logs`, `recommendation_experiments` | [ ] |
| Lookups `interaction_types`, `embedding_models`, `recommendation_sources`, `media_types` | [ ] |
| `users.short_term_embedding`, `users.avoid_embedding`                            | [ ]    |
| Canal de log `recommendation`                                                    | [ ]    |

---

## §7.3 Status por fase

| Fase | Título                                           | Status      |
|------|--------------------------------------------------|-------------|
| 0    | Infraestrutura                                   | Parcial — §0.1, §0.4, §0.7 parcial done; §0.2, §0.3, §0.5, §0.6, §0.8 pendentes |
| 1    | Log append-only de interações                    | Pendente    |
| 2    | Consolidação de embedding em `posts`             | Esqueleto existe; refatoração pendente |
| 3    | User embeddings (LT/ST/AV)                       | LT ingênuo existe; ST/AV pendentes |
| 4    | Dwell time                                       | Pendente    |
| 5    | Feed two-stage                                   | ANN ingênuo existe; pipeline completo pendente |
| 6    | Moderação                                        | Pendente    |
| 7    | Observabilidade                                  | Pendente    |
| 8    | Tooling de operador                              | Pendente    |
| 9    | A/B + random serving                             | Pendente    |
| 10   | Interest clusters                                | Pendente    |
| 11   | Housekeeping                                     | Pendente    |

## §7.4 Como usar este plano

- Para invocar um agente implementador: *"implemente Phase 2.3"* referencia uma unidade de trabalho fechada.
- Cada sub-fase cita **testes Pest** — a implementação só é `[x]` quando os testes listados passam.
- Phases são largamente **independentes** depois de Phase 0 e Phase 1 — Phase 3 (user embeddings) pode rodar em paralelo com Phase 2.3 (re-embed on update), por exemplo.
- Dependências críticas:
  - Phase 1 bloqueia Phase 3.4 e Phase 5 (interações são insumo).
  - Phase 2.1 bloqueia Phase 5.2 (candidate gen usa `posts.embedding`).
  - Phase 3.1 bloqueia Phase 5.3 (ranker usa `users.long_term_embedding`, etc.).
  - Phase 7.1 é pré-requisito de Phase 8.2 e Phase 9.

## Perguntas em aberto levantadas por este plano

1. **Deprecação de `likes` e `comments` como fonte de sinal**: após dual-write estar estável, zeramos a dependência dos jobs nessas tabelas e lemos apenas `post_interactions`? Impacta Phase 3.2 (fonte de verdade).
2. **Phase 10 via PHP puro vs extensão**: implementar k-means em PHP é simples mas lento. Alternativa: `pgml` / script Python externo. Decidir antes de Phase 10.1.
3. **Horizon vs queue database**: se Redis/Horizon for decidido fora de escopo do workshop, fallback é continuar com `database` driver — quebra §5.6 do overview (short-term em tempo real continua possível, mas Horizon dashboard some).
4. **Backfill de `post_interactions` a partir de `likes`/`comments` já existentes**: ao rodar Phase 1.2 pela primeira vez, seedamos `post_interactions` com os likes/comments históricos? Necessário para Phase 3.2 produzir LT útil no workshop.
