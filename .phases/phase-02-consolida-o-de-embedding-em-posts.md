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

