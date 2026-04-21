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
