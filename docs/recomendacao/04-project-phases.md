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

## Phase 0 — Infraestrutura de recomendação (smoke checks, sem testes Pest)

Objetivo: deixar o terreno pronto para as fases subsequentes.

### 0.1 Extensão pgvector + HNSW para 1536d
- [x] **0.1.1** Extensão `vector` habilitada (`Schema::ensureVectorExtensionExists()` em `2026_04_21_005536_create_post_embeddings_table.php`).
- [x] **0.1.2** HNSW com `vector_cosine_ops` criado em `post_embeddings.embedding` (dim 1536, confirmado funcional).
- [ ] **0.1.3** Smoke check: `SELECT extversion FROM pg_extension WHERE extname='vector'` retorna versão ≥ 0.7 (necessário para `halfvec`, não usado hoje mas útil documentar).

### 0.2 Migrations do schema alvo
- [ ] **0.2.1** Gerar e aplicar migrations derivadas de `03-database-schema.md`, na ordem do §7.1 deste documento (ver seção "Ordem sugerida de migrations" ao final). **Não modificar** migrations existentes — só criar novas.
- [ ] **0.2.2** Smoke check: `./vendor/bin/sail artisan migrate:status` sem pending após rodar.

### 0.3 Seeders de lookup
- [ ] **0.3.1** `InteractionTypeSeeder` — popula os 9 kinds com `default_weight` e `half_life_hours` da tabela §6 do overview.
- [ ] **0.3.2** `EmbeddingModelSeeder` — `gemini-embedding-2-preview` (slug, provider=`google`, dims=1536, is_active=true).
- [ ] **0.3.3** `RecommendationSourceSeeder` — `ann_long_term`, `ann_short_term`, `ann_cluster`, `trending`, `following`, `locality`, `explore`, `control`.
- [ ] **0.3.4** `MediaTypeSeeder` — `image`, `video`, `audio` com `mime_prefix`.
- [ ] **0.3.5** Registrar os 4 seeders em `DatabaseSeeder` (idempotentes via `firstOrCreate`).
- [ ] **0.3.6** Smoke check: após `db:seed`, `SELECT COUNT(*)` em cada lookup retorna o esperado.

### 0.4 Configuração do provider Gemini
- [x] **0.4.1** Bloco `services.gemini` em `config/services.php` com `key`, `embedding.model`, `embedding.dimensions`, `embedding.endpoint`, `embedding.max_images_per_request`, `embedding.timeout`.
- [x] **0.4.2** Vars de ambiente: `GEMINI_API_KEY`, `GEMINI_EMBEDDING_MODEL`, `GEMINI_EMBEDDING_DIMENSIONS` (presumidas no `.env.example`; adicionar se faltar).
- [ ] **0.4.3** Adicionar ao `.env.example`: `GEMINI_API_KEY=`, `GEMINI_EMBEDDING_MODEL=gemini-embedding-2-preview`, `GEMINI_EMBEDDING_DIMENSIONS=1536`.
- [ ] **0.4.4** Comando Artisan `rec:smoke-test-embedding` que chama `GeminiEmbeddingService::embed([['text' => 'hello world']])` e imprime sucesso/falha. Smoke only, sem teste automatizado.

### 0.5 Queue Redis + Horizon com supervisores nomeados
- [ ] **0.5.1** Adicionar serviço `redis` ao `compose.yaml` (imagem oficial `redis:alpine`), expor porta 6379 interna à rede do Sail.
- [ ] **0.5.2** `.env.example`: `QUEUE_CONNECTION=redis`, `REDIS_QUEUE_CONNECTION=default`, `CACHE_STORE=redis`.
- [ ] **0.5.3** Instalar Horizon: `composer require laravel/horizon` e publicar (`php artisan horizon:install`).
- [ ] **0.5.4** `config/horizon.php` com supervisores distintos:
  - `realtime` (short-term embedding, alta prioridade, 3 workers).
  - `embeddings` (geração de embedding de post, 2 workers, timeout 60s).
  - `clusters` (k-means, 1 worker, timeout 600s).
  - `longterm` (batch diário, 1 worker, timeout 1800s).
  - `traces` (recommendation_logs, 2 workers, fire-and-forget).
- [ ] **0.5.5** Rota `/horizon` protegida por gate (em `HorizonServiceProvider::gate()`).
- [ ] **0.5.6** Smoke check: `php artisan horizon:status` responde `running` após start.

### 0.6 Service classes e contratos (scaffold)
- [ ] **0.6.1** `app/Contracts/EmbeddingServiceContract.php` (interface) com `embed(array $parts, string $taskType): array`.
- [ ] **0.6.2** `App\Services\GeminiEmbeddingService` já existe — fazê-lo implementar o contrato (sem mudar comportamento).
- [ ] **0.6.3** Scaffold `App\Services\Recommendation\RecommendationService` com método `feedFor(User $user, int $page, int $pageSize): Collection` retornando TODO fixo por enquanto.
- [ ] **0.6.4** Scaffold `App\Services\Recommendation\UserEmbeddingService` com métodos placeholder `refreshLongTerm(User): void`, `refreshShortTerm(User): void`, `refreshAvoid(User): void`.
- [ ] **0.6.5** Scaffold `App\Services\Recommendation\CandidateGenerator` e `App\Services\Recommendation\Ranker` vazios.
- [ ] **0.6.6** Registrar no `AppServiceProvider` bind do contrato de embedding → implementação Gemini.

### 0.7 Observers registrados com handlers no-op
- [x] **0.7.1** `PostObserver` (existe, escuta `created`, dispara `GeneratePostEmbeddingJob`).
- [x] **0.7.2** `LikeObserver` (existe, escuta `created` e `deleted`, dispara `CalculateUserCentroidJob`).
- [ ] **0.7.3** `PostMediaObserver` — no-op neste MVP (handler vazio para `created`, `deleted`). Motivo: garantir hook para futura invalidação de embedding quando mídia muda.
- [ ] **0.7.4** `PostInteractionObserver` — no-op no scaffold; Phase 1 adiciona a lógica real.
- [ ] **0.7.5** `CommentObserver` — no-op no scaffold; Phase 1 dual-write.
- [ ] **0.7.6** `ReportObserver` — no-op no scaffold; Phase 6 incrementa `posts.reports_count`.
- [ ] **0.7.7** Registrar com atributo `#[ObservedBy(...)]` nos models correspondentes (padrão já usado em `Post` e `Like`).

### 0.8 Logging estruturado
- [ ] **0.8.1** Criar canal `recommendation` em `config/logging.php` (driver `daily`, formato JSON, retention 14 dias).
- [ ] **0.8.2** Contract `RankingTraceLogger` com método `trace(string $event, array $context): void`. Context **sempre** inclui:
  - `request_id` (UUID gerado no início do `feedFor()`)
  - `user_id`
  - `phase` (nome da fase do pipeline: `candidate_gen`, `ranking`, `mmr`, `quota`, `response`)
  - `source` (recommendation_source slug)
  - `post_id` (quando aplicável)
  - `scores` (partial + final)
  - `rank_position`
- [ ] **0.8.3** Middleware global que propaga `X-Request-Id` → log context (para correlacionar HTTP ↔ logs).
- [ ] **0.8.4** Doc no README: "como ler um ranking trace" (1 parágrafo).

---

## Phase 1 — Log append-only de interações (fundação dos sinais)

**Histórias:** US-026, US-010 (parcial).
**Pré-requisito:** Phase 0 completa.

### 1.1 Tabela `post_interactions`
- [ ] **1.1.1** Migration criando `post_interactions` conforme §5 do schema doc (indices incluídos).
- [ ] **1.1.2** Model `App\Models\PostInteraction` com `$fillable`, relações `user()`, `post()`, `type()`.
- [ ] **1.1.3** Factory `PostInteractionFactory` com states `like()`, `comment()`, `view()`, `hide()`, `report()`.
- **Pest tests (`tests/Feature/Rec/PostInteractionTest.php`):**
    - [ ] `post_interactions_table_has_expected_indexes` — usa `DB::select('SELECT indexname FROM pg_indexes ...')` para confirmar os 5 indexes.
    - [ ] `a_post_interaction_belongs_to_user_post_and_type` (relações).
    - [ ] `factory_states_produce_valid_interactions` (1 assertion por state).

### 1.2 `LikeObserver` dual-write (US-026, US-010)
- [ ] **1.2.1** Estender `LikeObserver::created` para também criar `PostInteraction(kind=like, weight=default_weight)`.
- [ ] **1.2.2** Estender `LikeObserver::deleted` para criar `PostInteraction(kind=unlike, weight=-0.5)` em vez de apenas disparar recompute.
- **Pest tests (`tests/Feature/Rec/LikeDualWriteTest.php`):**
    - [ ] `liking_a_post_creates_a_post_interaction_row` — cria Like, assert `PostInteraction::where(kind=like)` existe.
    - [ ] `unliking_a_post_creates_an_unlike_interaction` — dois interactions (like + unlike) após toggle.

### 1.3 `CommentObserver` dual-write (US-026)
- [ ] **1.3.1** Criar `CommentObserver::created` → `PostInteraction(kind=comment, weight=1.5)`.
- [ ] **1.3.2** Registrar com `#[ObservedBy]` em `Comment`.
- **Pest tests (`tests/Feature/Rec/CommentDualWriteTest.php`):**
    - [ ] `commenting_creates_a_post_interaction_row`.
    - [ ] `deleting_a_comment_does_not_create_a_reverse_interaction` (comentário deletado é raro; não vira sinal negativo).

### 1.4 Share action + observer (US-026)
- [ ] **1.4.1** UI: botão "Compartilhar" em `post.card` (Livewire action; neste workshop basta copiar URL para clipboard).
- [ ] **1.4.2** Action grava `PostInteraction(kind=share, weight=2.0)`.
- **Pest tests (`tests/Feature/Rec/ShareActionTest.php`):**
    - [ ] `share_action_records_a_share_interaction`.
    - [ ] `share_requires_authentication` (guest redirects).

---

## Phase 2 — Consolidação de embedding em `posts`

**Histórias:** US-011, US-014, US-022, US-032.

### 2.1 Schema: mover embedding para `posts`
- [ ] **2.1.1** Migration adicionar `posts.embedding vector(1536) null`, `posts.embedding_updated_at`, `posts.embedding_model_id`, `posts.reports_count int default 0`.
- [ ] **2.1.2** Migration adicionar HNSW: `CREATE INDEX posts_embedding_hnsw_idx ON posts USING hnsw (embedding vector_cosine_ops) WHERE embedding IS NOT NULL`.
- [ ] **2.1.3** Migration de backfill: `UPDATE posts SET embedding = pe.embedding, embedding_updated_at = pe.created_at, embedding_model_id = (SELECT id FROM embedding_models WHERE slug='gemini-embedding-2-preview') FROM post_embeddings pe WHERE pe.post_id = posts.id`.
- [ ] **2.1.4** Migration separada (final): `DROP TABLE post_embeddings` **só depois** de 2.2 e 2.3 estarem estáveis (apontar com comentário).
- **Pest tests (`tests/Feature/Rec/PostsEmbeddingSchemaTest.php`):**
    - [ ] `posts_table_has_embedding_columns` (pg_attribute lookup).
    - [ ] `hnsw_index_exists_on_posts_embedding` (pg_indexes lookup).
    - [ ] `backfill_migrated_existing_post_embeddings` — seed um post + post_embedding antes da migration, rodar e assertar `posts.embedding IS NOT NULL`.

### 2.2 `GeneratePostEmbeddingJob` → async + grava em `posts` (US-011)
- [x] **2.2.1** Job existe e chama `GeminiEmbeddingService::embed()`.
- [~] **2.2.2** Job atualmente é `dispatch_sync` pelo `PostObserver` e grava em `post_embeddings`. **Refatorar** para dispatch assíncrono na fila `embeddings` e gravar em `posts.embedding`.
- [ ] **2.2.3** Retry com backoff (3 tentativas, base 5s) + fallback para `failed_jobs` após esgotar.
- [ ] **2.2.4** Job atualiza `posts.embedding_updated_at` e `posts.embedding_model_id`.
- [ ] **2.2.5** Corrigir bug existente em `GeneratePostEmbeddingJob::handle()`: `if ($this->post->whereHas('media'))` é sempre truthy (retorna query builder); deve ser `if ($this->post->media()->exists())`.
- **Pest tests (`tests/Feature/Rec/GeneratePostEmbeddingJobTest.php`) — usa `Http::fake()` para o Gemini:**
    - [ ] `creating_a_post_dispatches_the_embedding_job_on_the_embeddings_queue` — `Queue::fake()`, cria post, `Queue::assertPushedOn('embeddings', GeneratePostEmbeddingJob::class)`.
    - [ ] `job_persists_embedding_to_posts_table` — `Http::fake()` Gemini retorna vetor válido; assertar `posts.embedding IS NOT NULL`.
    - [ ] `job_populates_embedding_updated_at_and_model_id`.
    - [ ] `job_retries_on_transient_failure` — `Http::fake()` 500 na primeira, 200 na segunda.
    - [ ] `job_sends_media_via_inline_data` — post com 1 imagem, assert payload Gemini contém `inline_data.mime_type`.
    - [ ] `job_no_ops_on_post_without_body_and_media`.
    - [ ] `text_only_post_generates_embedding_without_media_parts`.

### 2.3 Re-embedding on caption update (US-014)
- [ ] **2.3.1** `PostObserver::updated` detecta `$post->wasChanged('body')` e dispatcha `GeneratePostEmbeddingJob` com flag `replace=true`.
- [ ] **2.3.2** Job com `replace=true` sobrescreve embedding existente (não cria histórico — decisão §11.8 do overview resolvida).
- **Pest tests (`tests/Feature/Rec/RegenerateEmbeddingOnUpdateTest.php`):**
    - [ ] `updating_post_body_dispatches_regeneration_job`.
    - [ ] `updating_post_without_body_change_does_not_regenerate`.
    - [ ] `regeneration_overwrites_previous_embedding` — check `embedding_updated_at` avança.

### 2.4 Backfill command estendido (US-022)
- [x] **2.4.1** Comando `app:generate-post-embeddings` já existe com `--force` e `--chunk`.
- [ ] **2.4.2** Adicionar opções: `--since=YYYY-MM-DD`, `--post-ids=1,2,3`, `--dispatch-queue` (default hoje: sync; com flag, enfileira).
- [ ] **2.4.3** Adicionar comando `app:rebackfill-post-embeddings-for-model {model_slug}` que regera só os posts cujo `embedding_model_id` difere do atual (para troca de modelo).
- **Pest tests (`tests/Feature/Rec/GeneratePostEmbeddingsCommandTest.php`):**
    - [ ] `command_processes_all_posts_without_embedding` (with `Queue::fake` or `Http::fake`).
    - [ ] `--force_regenerates_existing_embeddings`.
    - [ ] `--since_filters_by_created_at`.
    - [ ] `--post-ids_filters_to_subset`.
    - [ ] `--dispatch-queue_enqueues_instead_of_running_sync`.

### 2.5 Degradação graciosa na API Gemini (US-032)
- [ ] **2.5.1** Circuit breaker: contador em Redis `rec:gemini:failures` com TTL 60s. Após 10 falhas consecutivas, marca Redis `rec:gemini:circuit_open` com TTL 5min.
- [ ] **2.5.2** Job consulta circuit breaker antes de chamar API — se aberto, `release(300)` (re-enfileira em 5min).
- [ ] **2.5.3** Feed continua funcionando com posts que já têm embedding (ranking pura por LT/ST existentes).
- **Pest tests (`tests/Feature/Rec/GeminiCircuitBreakerTest.php`):**
    - [ ] `circuit_opens_after_consecutive_failures` — `Http::fake` 500 × 10, assert Redis key `rec:gemini:circuit_open`.
    - [ ] `circuit_closes_after_ttl` — fake clock.
    - [ ] `job_is_released_when_circuit_is_open` (não chama Gemini, `release()` é invocado).

---

## Phase 3 — User embeddings (long-term, short-term, avoid)

**Histórias:** US-027, US-028, US-029, US-002, US-005.

### 3.1 Schema: rename + novos vetores em `users`
- [ ] **3.1.1** Migration: `ALTER TABLE users RENAME COLUMN embedding TO long_term_embedding`.
- [ ] **3.1.2** Adicionar colunas: `long_term_embedding_updated_at`, `long_term_embedding_model_id`, `short_term_embedding vector(1536) null`, `short_term_embedding_updated_at`, `short_term_embedding_model_id`, `avoid_embedding vector(1536) null`, `avoid_embedding_updated_at`, `avoid_embedding_model_id`.
- [ ] **3.1.3** HNSW nos 3 vetores (`vector_cosine_ops WHERE embedding IS NOT NULL`).
- [ ] **3.1.4** Atualizar `App\Models\User` `$casts` e `$fillable` (remover `embedding`, adicionar os 3 novos).
- **Pest tests (`tests/Feature/Rec/UserEmbeddingsSchemaTest.php`):**
    - [ ] `users_table_has_three_embedding_columns_and_metadata_pairs`.
    - [ ] `hnsw_indexes_exist_on_all_three_vectors`.

### 3.2 `RefreshLongTermEmbeddingsJob` (US-027)
- [ ] **3.2.1** Criar job na fila `longterm`, limite 30min.
- [ ] **3.2.2** Agregação: para cada user com atividade nos últimos 7d, lê `post_interactions` positivas dos últimos 180d, aplica `w_eff = w_base · exp(-ln(2) · age_days / 30)`, normaliza L2.
- [ ] **3.2.3** Se `Σ w_eff < 2.0` (threshold configurável), grava NULL.
- [ ] **3.2.4** Agenda `Schedule::job(...)->dailyAt('03:00')->onTimezone('America/Sao_Paulo')`.
- [ ] **3.2.5** Deprecar `CalculateUserCentroidJob` (renomear para `CalculateUserCentroidJob.deprecated` e deixar de dispatchá-lo).
- **Pest tests (`tests/Feature/Rec/RefreshLongTermEmbeddingsJobTest.php`):**
    - [ ] `job_populates_long_term_embedding_for_users_with_activity`.
    - [ ] `job_respects_180_day_window` — seed interaction em `now() - 200 days`, assert ignorada.
    - [ ] `job_applies_weighted_mean_with_decay` — dois posts, um novo com weight baixo, outro antigo com weight alto — resultado é ponderado.
    - [ ] `job_writes_null_below_threshold`.
    - [ ] `job_skips_inactive_users` (sem interação há 30 dias).
    - [ ] `long_term_embedding_updated_at_is_touched`.

### 3.3 `RefreshShortTermEmbeddingJob` (US-028)
- [ ] **3.3.1** Job na fila `realtime`.
- [ ] **3.3.2** Debounce via Redis lock `rec:user:{id}:st_lock` (TTL 5s).
- [ ] **3.3.3** Janela: últimas 48h, half-life 6h.
- [ ] **3.3.4** Escrita dupla: `users.short_term_embedding` (Postgres) + `rec:user:{id}:short_term` (Redis, TTL 1h).
- **Pest tests (`tests/Feature/Rec/RefreshShortTermEmbeddingJobTest.php`):**
    - [ ] `job_populates_short_term_from_last_48h_interactions`.
    - [ ] `job_caches_to_redis` (assert `Redis::exists('rec:user:1:short_term')`).
    - [ ] `debounce_drops_concurrent_dispatches` — dispatch 3× em sequência, Queue::assertPushed exatamente 1.
    - [ ] `short_term_is_null_when_no_recent_interaction`.

### 3.4 Disparo em tempo real a partir de interações (US-002)
- [ ] **3.4.1** `PostInteractionObserver::created` dispatcha `RefreshShortTermEmbeddingJob`.
- [ ] **3.4.2** Também appenda em `rec:user:{id}:short_term_buffer` (lista Redis, max 50 items).
- **Pest tests (`tests/Feature/Rec/ShortTermDispatchTest.php`):**
    - [ ] `positive_interaction_dispatches_short_term_refresh`.
    - [ ] `negative_interaction_does_not_dispatch_short_term` (hide/report vão para AV, não ST).
    - [ ] `dispatch_is_debounced_within_5s`.

### 3.5 `RefreshAvoidEmbeddingJob` (US-029)
- [ ] **3.5.1** Piggyback no `RefreshLongTermEmbeddingsJob`: no mesmo loop, calcula AV a partir de `post_interactions` negativas (hide, report, skip_fast) dos últimos 90d.
- [ ] **3.5.2** Half-lives conforme §6 do overview.
- **Pest tests (`tests/Feature/Rec/RefreshAvoidEmbeddingTest.php`):**
    - [ ] `avoid_embedding_is_populated_from_hides_and_reports`.
    - [ ] `avoid_is_null_for_users_without_negative_signals`.
    - [ ] `skip_fast_signals_contribute_to_avoid`.

### 3.6 `UserEmbeddingService` (orchestrador)
- [ ] **3.6.1** Preencher `refreshLongTerm(User)`, `refreshShortTerm(User)`, `refreshAvoid(User)` com a lógica acima.
- [ ] **3.6.2** Método helper `readShortTerm(User): ?array` com cache Redis → fallback Postgres.
- **Pest tests (`tests/Feature/Rec/UserEmbeddingServiceTest.php`):**
    - [ ] `readShortTerm_returns_redis_cached_value`.
    - [ ] `readShortTerm_falls_back_to_postgres_on_miss`.

---

## Phase 4 — Captura de dwell time

**Histórias:** US-008.

### 4.1 Frontend: IntersectionObserver + beacon
- [ ] **4.1.1** Componente Livewire `post.card` emite, via Alpine, evento `view-event` quando o card entra/sai do viewport (≥50%).
- [ ] **4.1.2** Buffer client-side agrega eventos e envia em batch:
  - A cada 15s (intervalo) OU
  - No `beforeunload` via `navigator.sendBeacon`.
- [ ] **4.1.3** Endpoint `POST /api/rec/view-events` (aceita JSON array, auth required). Cria N `PostInteraction(kind=view OR skip_fast, duration_ms=...)` conforme curva §5.4 do overview.
- **Pest tests (`tests/Feature/Rec/ViewEventsApiTest.php`):**
    - [ ] `authenticated_user_can_post_view_events` (200, rows criadas).
    - [ ] `guest_receives_401`.
    - [ ] `batch_with_mixed_durations_creates_correct_kinds` — 500ms→skip_fast, 5s→view, 35s→view capped.
    - [ ] `neutral_dwell_between_1_and_3s_is_not_recorded`.
    - [ ] `duration_ms_is_persisted`.
    - [ ] `view_weight_follows_documented_curve` — parametrizar com dataset.

### 4.2 `AggregateViewSignalsJob`
- [ ] **4.2.1** Job em `Schedule::command(...)->everyTenMinutes()` para usuários com atividade view nos últimos 10min.
- [ ] **4.2.2** Recalcula short-term do user se `Σ new_weight > threshold_delta` (evita thrashing).
- **Pest tests (`tests/Feature/Rec/AggregateViewSignalsJobTest.php`):**
    - [ ] `job_triggers_st_refresh_for_users_with_new_view_events`.
    - [ ] `job_is_noop_if_delta_below_threshold`.

---

## Phase 5 — Feed: retrieval em dois estágios

**Histórias:** US-001, US-005, US-006, US-007, US-012, US-013, US-030.

### 5.1 `TrendingPoolService` + `RefreshTrendingPoolJob` (US-030)
- [ ] **5.1.1** Job a cada 5min (`Schedule::job(...)->everyFiveMinutes()`).
- [ ] **5.1.2** Calcula `trending_score = Σ interactions.weight · exp(-ln(2) · age_hours / 24) / (impressions_count + 1)` nas últimas 24h.
- [ ] **5.1.3** Persiste top-200 em Redis sorted set `rec:trending:global`.
- **Pest tests (`tests/Feature/Rec/TrendingPoolJobTest.php`):**
    - [ ] `job_writes_top_n_to_redis_sorted_set`.
    - [ ] `trending_excludes_posts_with_reports_over_threshold`.
    - [ ] `trending_normalizes_by_impressions`.
    - [ ] `trending_respects_24h_window`.

### 5.2 `CandidateGenerator`
- [ ] **5.2.1** `annByLongTerm(User, limit=300)` — `ORDER BY embedding <=> user.long_term_embedding` (pgvector).
- [ ] **5.2.2** `annByShortTerm(User, limit=200)`.
- [ ] **5.2.3** `trending(User, limit=100)` — lê Redis.
- [ ] **5.2.4** `exploration(User, limit=50)` — posts de autores nunca vistos + recentes.
- [ ] **5.2.5** `generate(User)` unifica fontes, dedup por `post_id`, aplica filtros duros (já visto, autor bloqueado, `posts.reports_count < threshold`, post do próprio usuário, `posts.deleted_at is null`).
- [ ] **5.2.6** Cada candidato carrega metadata `['source' => 'ann_long_term', 'source_score' => 0.82]`.
- **Pest tests (`tests/Feature/Rec/CandidateGeneratorTest.php`):**
    - [ ] `annByLongTerm_returns_closest_posts_by_cosine`.
    - [ ] `annByShortTerm_returns_closest_posts_to_short_term`.
    - [ ] `trending_reads_from_redis_sorted_set`.
    - [ ] `exploration_excludes_posts_from_authors_already_seen`.
    - [ ] `generate_dedups_across_sources`.
    - [ ] `generate_filters_already_seen`.
    - [ ] `generate_filters_reports_over_threshold`.
    - [ ] `generate_filters_own_posts`.

### 5.3 `Ranker`
- [ ] **5.3.1** `score(Post, User)` = `α · cos(p, LT) + (1-α) · cos(p, ST) - β · cos(p, AV) + γ · recency_boost + δ · trending_boost + ε · context_boost`.
- [ ] **5.3.2** Pesos lidos de `config('recommendation.score.*')` — NUNCA hardcoded.
- [ ] **5.3.3** α dinâmico: heurística simples (se `interactions_last_24h >= 5`, α=0.3; senão α=0.8). `[DECISÃO PENDENTE §11.1 do overview]`.
- [ ] **5.3.4** Recency boost: `exp(-ln(2) · age_hours / 6)`.
- **Pest tests (`tests/Feature/Rec/RankerTest.php`):**
    - [ ] `score_combines_lt_and_st_with_alpha`.
    - [ ] `score_penalizes_posts_close_to_avoid`.
    - [ ] `score_falls_back_when_lt_is_null`.
    - [ ] `recency_boost_decays_with_half_life_6h`.
    - [ ] `alpha_shifts_toward_short_term_when_session_is_active` (US-012 implícito).

### 5.4 MMR re-rank (US-006)
- [ ] **5.4.1** `applyMmr(array $ranked, float $lambda, int $poolSize)` — iterativo, `max_{d' in selected} sim(d, d')`.
- [ ] **5.4.2** λ default 0.7 em config.
- **Pest tests (`tests/Feature/Rec/MmrTest.php`):**
    - [ ] `mmr_prevents_adjacent_similar_posts` — seed 10 posts quase idênticos + 10 variados, assert top-10 inclui variedade.
    - [ ] `mmr_is_identity_when_lambda_is_1`.
    - [ ] `mmr_degrades_to_popularity_when_lambda_is_0`.

### 5.5 Author quota (US-013)
- [ ] **5.5.1** `applyAuthorQuota(array $ranked, int $topK=20, int $perAuthor=2)` — passo pós-MMR.
- [ ] **5.5.2** Parametrizado via config.
- **Pest tests (`tests/Feature/Rec/AuthorQuotaTest.php`):**
    - [ ] `top_k_has_at_most_n_posts_per_author`.
    - [ ] `underfilled_quota_promotes_next_candidate_respecting_rule`.

### 5.6 "Já visto" via Redis (US-001)
- [ ] **5.6.1** Após cada renderização de feed, adicionar `post_id`s em `rec:user:{id}:seen` (set, TTL 48h).
- [ ] **5.6.2** `CandidateGenerator::generate` filtra posts presentes no set.
- **Pest tests (`tests/Feature/Rec/AlreadySeenFilterTest.php`):**
    - [ ] `seen_posts_are_not_returned_in_next_feed`.
    - [ ] `seen_ttl_is_48_hours` (mock clock).

### 5.7 Cold-start path (US-005)
- [ ] **5.7.1** `RecommendationService::feedFor` detecta `user.long_term_embedding IS NULL AND interactions_count < 5` → retorna `trending + recent` intercalados (1 recent a cada 5).
- [ ] **5.7.2** Após 5ª interação positiva, promove para recommendation path com α=0.3 (override temporário).
- **Pest tests (`tests/Feature/Rec/ColdStartTest.php`):**
    - [ ] `new_user_gets_trending_blended_with_recent_posts`.
    - [ ] `cold_start_interleaves_one_recent_per_five_trending`.
    - [ ] `user_promoted_to_recommendation_path_after_5th_positive_interaction`.

### 5.8 `RecommendationService::feedFor` final
- [ ] **5.8.1** Orquestra tudo: cold-start check → candidate gen → ranking → MMR → quota → seen filter → return.
- [ ] **5.8.2** Gera `request_id` UUID e passa para `RankingTraceLogger`.
- [ ] **5.8.3** Plug no `App\Livewire\Pages\Feed\Index::render()` — **remove** `orderByRaw('... <=> ?::vector')` atual, delega para service.
- **Pest tests (`tests/Feature/Rec/FeedPipelineTest.php`):**
    - [ ] `feed_returns_posts_ordered_by_composite_score` (integração larga com factories).
    - [ ] `feed_excludes_posts_without_embedding`.
    - [ ] `feed_p50_latency_under_250ms` — `Benchmark::measure()` (histórico em CI, não bloqueante).

### 5.9 Exploration slot (US-007)
- [ ] **5.9.1** Garantir que `CandidateGenerator::exploration` contribui ≥1 post em cada janela de 10 no ranked output.
- [ ] **5.9.2** `source='explore'` explícito no metadata (vira `recommendation_logs.recommendation_source_id`).
- **Pest tests (`tests/Feature/Rec/ExplorationSlotTest.php`):**
    - [ ] `feed_includes_at_least_one_exploration_post_per_10`.
    - [ ] `exploration_source_is_tagged_in_ranking_logs`.

---

## Phase 6 — Moderação: hide, report, retirada automática

**Histórias:** US-003, US-004, US-015.

### 6.1 Ação Hide (US-003)
- [ ] **6.1.1** Botão "Esconder" em `post.card` (menu "..."); Livewire action cria `PostInteraction(kind=hide, weight=-1.5)`.
- [ ] **6.1.2** Adiciona `post_id` em `rec:user:{id}:hidden` (set Redis persistente).
- [ ] **6.1.3** `CandidateGenerator::generate` consulta esse set em filtro duro.
- **Pest tests (`tests/Feature/Rec/HidePostTest.php`):**
    - [ ] `hiding_a_post_creates_hide_interaction`.
    - [ ] `hidden_post_does_not_appear_in_next_feed`.
    - [ ] `hide_requires_authentication`.

### 6.2 Tabela `reports` + ReportObserver (US-004)
- [ ] **6.2.1** Migration `reports` conforme schema doc.
- [ ] **6.2.2** Model `Report` com relações + factory.
- [ ] **6.2.3** Botão "Reportar" em `post.card`, modal com motivos.
- [ ] **6.2.4** `ReportForm` Livewire Form Object com `post_id`, `reason`, `details`.
- [ ] **6.2.5** Grava `Report` + `PostInteraction(kind=report, weight=-3.0)`.
- [ ] **6.2.6** `ReportObserver::created` incrementa `posts.reports_count` (ver decisão §11.3 do schema doc).
- **Pest tests (`tests/Feature/Rec/ReportPostTest.php`):**
    - [ ] `reporting_creates_report_row_and_interaction`.
    - [ ] `posts_reports_count_is_incremented_on_report`.
    - [ ] `user_cannot_report_same_post_twice` (unique index).
    - [ ] `reported_post_disappears_from_reporter_feed`.

### 6.3 Retirada automática por reports threshold (US-015)
- [ ] **6.3.1** Threshold inicial: `config('recommendation.moderation.reports_threshold') = 5`.
- [ ] **6.3.2** Filtro duro no `CandidateGenerator`: `WHERE posts.reports_count < threshold`.
- [ ] **6.3.3** Post permanece na timeline do autor (não deletado; apenas filtrado do feed de outros).
- **Pest tests (`tests/Feature/Rec/ReportsThresholdTest.php`):**
    - [ ] `post_with_reports_above_threshold_is_excluded_from_feed`.
    - [ ] `post_below_threshold_still_appears`.
    - [ ] `threshold_is_configurable`.

### 6.4 Comando de reversão (US-022 complementar)
- [ ] **6.4.1** `rec:reverse-report {post_id}` — zera `reports_count` e marca `reports.resolved_at`.
- **Pest tests (`tests/Feature/Rec/ReverseReportCommandTest.php`):**
    - [ ] `command_zeros_reports_count`.
    - [ ] `command_sets_resolved_at_on_all_open_reports_for_post`.

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
