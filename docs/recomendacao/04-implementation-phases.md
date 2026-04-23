# Fases de Implementação — Sistema de Recomendação

> Plano incremental de entrega derivado de `01-overview.md`, `02-user-stories.md` e `03-database-changes.md`. Cada fase é "final em si mesma" (entrega valor utilizável e pode ser deixada em produção indefinidamente) e é gated por um toggle em `config/recommender.php` que permite desligar a funcionalidade sem reverter código.

---

## 0. Decisões que atravessam o plano todo

Antes das fases, três decisões de escopo importantes — uma delas **difere** do que o `02-user-stories.md` registrou, e precisa estar clara:

### 0.1. Feature flags: reconciliação com o `02`

O `02-user-stories.md` diz explicitamente: "Sem A/B testing nem feature flags — mudanças entram via deploy; pesos vivem em `config/recommender.php`". O enunciado deste plano, em contrapartida, pede "feature flags em tudo".

**Reconciliação adotada**: cada fase é gated por uma **config flag booleana** (`config('recommender.features.<nome>', false)`). Não é A/B runtime nem rollout por % de usuários — é um toggle de deploy que, quando `false`, faz o `FeedService`/`InteractionService` cair no caminho legado (ou no comportamento da fase anterior). Funciona como kill-switch sem revert de código. Isso preserva a decisão de "sem A/B" do `02` e ao mesmo tempo honra o princípio de "rollout independente por fase" pedido aqui.

Os toggles são:

```php
// config/recommender.php
'features' => [
    'async_embedding'      => env('RECOMMENDER_ASYNC_EMBEDDING', false),   // Fase 1
    'interaction_stream'   => env('RECOMMENDER_INTERACTION_STREAM', false),// Fase 1
    'new_feed_service'     => env('RECOMMENDER_NEW_FEED', false),          // Fase 1
    'ranking_logs'         => env('RECOMMENDER_LOG_RANKING', false),       // Fase 1
    'trending_pool'        => env('RECOMMENDER_TRENDING_POOL', false),     // Fase 1
    'negative_signals'     => env('RECOMMENDER_NEGATIVE', false),          // Fase 2
    'mmr_diversity'        => env('RECOMMENDER_MMR', false),               // Fase 2
    'long_term_cron'       => env('RECOMMENDER_LT_CRON', false),           // Fase 3
    'random_baseline'      => env('RECOMMENDER_RANDOM_BASELINE', false),   // Fase 3
    'multi_interest'       => env('RECOMMENDER_MULTI_INTEREST', false),    // Fase 4
],
```

### 0.2. Queue & Horizon

Decisão adotada para este plano (resolve `overview §11.1` e `US-018`): **manter `QUEUE_CONNECTION=database` na Fase 0, migrar para `redis` na Fase 1, instalar `laravel/horizon` na Fase 1**. Motivo: a Fase 1 é a primeira que realmente cria filas com perfis distintos (`embeddings`, `realtime`, `batch`, `ingest`); antes disso não há ganho em subir Horizon. A migração em si é de baixo risco (swap de driver + install), mas paga a dívida para toda fase seguinte.

### 0.3. Estado de entrada do projeto (o que já existe)

Ancoragem do plano no código atual, para não planejar trabalho já feito:

| Já existe | Estado | Implicação |
|---|---|---|
| `post_embeddings` + HNSW | Em uso | Fase 0 só adiciona colunas de metadado |
| `users.embedding` (legado) | Em uso | Coexiste com os novos vetores; corte fica para pós-Fase 3 |
| `GeminiEmbeddingService` | HTTP direto, sem `laravel/ai` | Mantido. Migração para `laravel/ai` fica como débito consciente |
| `GeneratePostEmbeddingJob` | Funcional, `dispatch_sync` no observer | Fase 1 troca para `dispatch()` + fila `embeddings` |
| `CalculateCentroidJob` + `LikeObserver` | Em uso, alimenta `users.embedding` | Fase 1 aposenta — `InteractionService` assume |
| `App\Livewire\Pages\Feed\Index` | Ordena por `<=>` direto | Fase 1 refatora para `FeedService` com Stage 1+2 |
| Comando `app:generate-post-embeddings` | Existe, funciona | Fase 1 adapta para enfileirar (coerente com `US-020`) |
| Migrations 1-7 do `03` | **Não aplicadas** (são design) | Fase 0 aplica todas |

### 0.4. Escopo ausente de CI/CD

Não há `.github/`, Gitlab CI, nem pipeline automatizada no repo. Deploy é manual via Sail. Toda menção a "rollout" neste documento assume uma operação manual feita pelo instrutor/dev: `composer install && artisan migrate && npm run build && artisan horizon:terminate` (quando aplicável) + flip da flag. Não há canary automatizado.

---

## 1. Visão geral

| # | Título | Valor entregue ao final | Esforço | Dependências | US cobertas |
|---|---|---|---|---|---|
| 0 | Preparação de schema e infra | Schema pronto, config centralizada, seeds demo; sem mudança de comportamento no feed | 2-3 dias | Nenhuma | US-019 (parcial), US-018 (parcial) |
| 1 | MVP multi-sinal assíncrono com logging | Feed personalizado em dois estágios, embedding async, comment com peso, trending pool para cold start, logs de ranking, operador monitora filas e debuga "por que esse post?" | 8-12 dias | Fase 0 | US-001, 002, 003, 007, 009, 013, 014, 017, 018, 019, 020, 023, 024, 027, 028, 029 |
| 2 | Sinais negativos, dwell, diversidade | Hide/skip/dwell capturados; avoid filtra semanticamente; MMR + cota diversificam; share; report com log | 7-10 dias | Fase 1 | US-004, 005, 006, 008, 010, 011, 015, 026 |
| 3 | Cron diário, recency, exploration, dashboards | Long-term estável; random baseline prova valor do ranker; dashboards de métricas; boosts de recency/exploration; resiliência quando Redis cair; operador revisa reports | 6-8 dias | Fase 2 | US-012, 016, 021, 022, 025, 030, 031, 032 |
| 4 | Multi-interest (roadmap, opcional) | Clusters k-means por usuário alimentam candidate gen; captura interesses disjuntos | 5-7 dias | Fase 3 | (sem US — roadmap do `overview §4`) |

**Total MoSCoW**: Fases 0-2 fecham 100% dos `Must`. Fase 3 fecha os `Should`. Fase 4 é exclusivamente roadmap.

**Total de esforço estimado**: 23-33 dias ideais de dev (dia ideal = 6h de foco) para chegar até a Fase 3. A Fase 4 adiciona 5-7 dias se e quando for priorizada.

---

## 2. Roadmap e sequenciamento

```
Fase 0 ──► Fase 1 ──► Fase 2 ──► Fase 3 ──► Fase 4 (opcional)
                       │
                       └──► (algumas histórias de Fase 3 podem antecipar — ver paralelização abaixo)
```

### Sequencial (um dev)

A ordem acima é obrigatória: cada fase assume a anterior deployada.

### Paralelização possível (múltiplos devs)

Na **Fase 1** (o grosso do trabalho), com 2-3 devs dá para paralelizar:
- Dev A: `PostObserver` async + `GeneratePostEmbeddingJob` + config Horizon (US-013, US-023, US-020)
- Dev B: `InteractionService` + `UpdateShortTermEmbeddingJob` + Redis wiring (US-002, US-003, US-024)
- Dev C: `FeedService` (Stage 1+2 sem MMR ainda) + `ranking_logs` + `ComputeTrendingPoolJob` (US-001, US-007, US-009, US-027, US-028)

Condição de merge: os três caminhos precisam do schema da Fase 0 **e** se encontram em `FeedService::build()` no final.

Na **Fase 2**:
- Dev A: dwell tracking client-side (Alpine + endpoint API) + `RecordDwellEventsJob` (US-005, US-006)
- Dev B: hide/report UI + `UpdateAvoidEmbeddingJob` + avoid filter no ranker (US-004, US-011, US-026)
- Dev C: MMR + cota por autor + share (US-008, US-010, US-015)

Na **Fase 3**, a maior parte do trabalho é cron/dashboards e é relativamente acoplada: 1-2 devs é suficiente, paralelização não ajuda tanto.

### Antecipações seguras

Algumas histórias da Fase 3 podem ser puxadas para Fase 2 se o time tiver folga:
- `US-016` (recency boost) é tunning de score — 2-3h, pode entrar já na Fase 2.
- `US-012` (exploration slot) é SQL extra no candidate gen — 3-4h, pode entrar já na Fase 2.

Movê-las não quebra dependências e economiza um sub-release.

---

## 3. Detalhamento por fase

---

### Fase 0 — Preparação de schema e infra

**Objetivo:** aplicar todo o schema novo (7 migrations do `03`), publicar `config/recommender.php` com os parâmetros default e um seeder que hidrata `interaction_events` a partir do seed atual. Nenhum comportamento do feed muda.

**Valor entregue ao final:** instrutor/operador pode inspecionar o novo schema, rodar queries de demonstração contra `interaction_events` populado pelo seeder, e tem um ponto central (`config/recommender.php`) para ajustar parâmetros sem code search. Zero risco para o usuário final — feed continua exatamente como está.

**User stories cobertas:** US-019 (parcial — só cria a estrutura; as chaves só passam a ser lidas na Fase 1), US-018 (parcial — cria as 4 filas em `config/queue.php`, ainda não usadas).

**Escopo técnico:**
- Aplicar as 7 migrations do `03-database-changes.md §7`.
- Criar `config/recommender.php` com **todas** as chaves listadas em `US-019`, cada uma com `env()` fallback e default ancorado no `overview`.
- Criar o seeder `RecommendationDemoSeeder` (hidrata `interaction_events` a partir de `likes`/`comments` existentes, com `created_at` preservado e `weight` derivado da tabela `§6` do overview).
- Definir as 4 filas em `config/queue.php` (`embeddings`, `realtime`, `batch`, `ingest`) — apenas a estrutura; jobs ainda apontam para `default`.
- Adicionar `RECOMMENDER_*` no `.env.example` com defaults `false`.

**Fora de escopo (explícito):**
- Qualquer mudança em observers, jobs ou componentes Livewire.
- Instalação de Horizon (fica pra Fase 1).
- Troca de `QUEUE_CONNECTION` (fica pra Fase 1).
- `RefreshInterestClustersJob` e consumo de `user_interest_clusters` (fica pra Fase 4 — só a tabela existe aqui, vazia).
- Remoção de `users.embedding` e `CalculateCentroidJob` (só acontecem após Fase 3 estabilizar).

**Arquivos / áreas afetadas:**
- `database/migrations/2026_04_23_090001_*` a `2026_04_23_090007_*` — **novos** (7 arquivos, conforme `03 §7`).
- `database/seeders/RecommendationDemoSeeder.php` — **novo** (conforme `03 §7`).
- `database/seeders/DatabaseSeeder.php` — **modificado**: chamar `RecommendationDemoSeeder` após seeds existentes.
- `config/recommender.php` — **novo**.
- `config/queue.php` — **modificado**: adicionar connections/queues (ainda não usadas).
- `.env.example` — **modificado**: listar `RECOMMENDER_*` com `false`.

**Testes necessários:**
- Unitários: nenhum (é só schema/config).
- Feature/Integration: um teste de migrations (roda `migrate:fresh --seed` e confere que as tabelas novas existem com as colunas esperadas, usando `Schema::hasColumn()`). Um teste de que `config('recommender.features.async_embedding')` retorna `false` sem `env` e o valor de `env` quando setado.
- Teste de carga: não aplicável.

**Feature flag:** nenhuma específica — a Fase 0 é só estrutura. Todas as flags das fases seguintes são criadas aqui em `false`.

**Rollout plan:**
1. Merge na `main`.
2. Deploy: `composer install && vendor/bin/sail artisan migrate && vendor/bin/sail artisan db:seed --class=RecommendationDemoSeeder`.
3. Nenhuma flag para flipar.
4. Smoke test manual: abrir o feed, confirmar comportamento idêntico ao pré-deploy.

**Risco e mitigação:**

| Risco | Impacto | Probabilidade | Mitigação |
|---|---|---|---|
| HNSW em `users.long_term_embedding` lento em produção | Baixo (demora na migration) | Baixa | Migration usa `CREATE INDEX CONCURRENTLY` (ver `03 §4`). Em 300 users é <5s de qualquer forma. |
| Seeder gera volume muito alto de `interaction_events` | Baixo (lentidão local) | Baixa | Seeder limitado a hidratar o que já existe (~poucos milhares de linhas no seed). |
| Conflito de timestamps de migration com PR concorrente | Baixo (rebase manual) | Média | Reservar o bloco `2026_04_23_0900*` para este trabalho. |

**Estimativa:** **2-3 dias**.

**Critérios de "pronto":**
- [ ] `vendor/bin/sail artisan migrate:fresh --seed` roda limpo em dev.
- [ ] `SELECT COUNT(*) FROM interaction_events` retorna >0 após seed.
- [ ] `config('recommender.signal_weights.like')` retorna o default esperado.
- [ ] Feed atual continua funcionando como antes (regressão manual).
- [ ] Testes da suíte atual passam: `vendor/bin/sail artisan test --compact`.

**Métricas a monitorar em produção:** nenhuma nova nesta fase.

**Dependência externa:** nenhuma — não chama Gemini, não depende de Redis ainda.

---

### Fase 1 — MVP multi-sinal assíncrono com logging

**Objetivo:** transformar o feed atual em um feed de dois estágios com candidate generation (ANN + trending) e ranking simples (score composto sem MMR, sem avoid, sem dwell). Embedding de post vira assíncrono. Interações (like, comment) alimentam `interaction_events` e atualizam short-term no Redis em tempo real. Operador já consegue debugar "por que esse post?" via `ranking_logs`.

**Valor entregue ao final:** o feed passa de "ordem por similaridade com centroide legado" para "ranker real com dois estágios, short-term em tempo real e logs completos". Criar post não bloqueia mais a UI. Cold start (usuário novo) cai em trending com diversidade por ordem e MMR fraco (via fallback). O operador tem dashboard de filas (Horizon) e pode rodar `artisan recommender:explain` para entender qualquer posição servida.

**User stories cobertas:** US-001, US-002, US-003, US-007, US-009, US-013, US-014, US-017, US-018, US-019, US-020, US-023, US-024, US-027, US-028, US-029.

**Escopo técnico:**
- Instalar `laravel/horizon` e configurar supervisors para `embeddings`, `realtime`, `batch`, `ingest`.
- Mover `QUEUE_CONNECTION=database` → `QUEUE_CONNECTION=redis`. Swap em `.env.example`.
- `PostObserver::created`: trocar `dispatch_sync` por `GeneratePostEmbeddingJob::dispatch()` + `onQueue('embeddings')`. Adicionar `$tries=3`, `$backoff=[10,60,300]` no job.
- `GeneratePostEmbeddingJob`: gravar em `embedding_jobs` também (start/success/failed), wire `embedded_at` e `embedding_model` em `post_embeddings`.
- Criar `App\Services\InteractionService` com método `record(User, Post, InteractionType, ?float $weightOverride=null)`. Ele grava em `interaction_events` **e** dispara `UpdateShortTermEmbeddingJob` na fila `realtime` + seta flag Redis `long_term:dirty:{userId}`.
- Reescrever `App\Observers\LikeObserver` para delegar ao `InteractionService` (remove o `dispatch(CalculateCentroidJob)`). `CalculateCentroidJob` e `users.embedding` continuam vivos (coexistência).
- Criar `App\Observers\CommentObserver` que delega ao `InteractionService` com `InteractionType::Comment`.
- Criar `App\Jobs\UpdateShortTermEmbeddingJob`: lê Redis → fallback Postgres → fallback zero vector; aplica `v_new = normalize(decay · v_old + weight · v_post)`; grava Redis com TTL 48h; snapshot Postgres a cada N=20 atualizações.
- Criar `App\Jobs\ComputeTrendingPoolJob` em cron a cada 15min (fila `batch`): grava zset `trending:global` no Redis.
- Criar `App\Services\FeedService::build(User, int $perPage, ?string $sessionId)`:
  - Lê short-term (Redis → Postgres → null).
  - Lê long-term (Postgres `users.long_term_embedding` → fallback `users.embedding` legado → null).
  - α dinâmico conforme `overview §5.5`.
  - Stage 1: ANN (pgvector, com `WHERE NOT IN (hidden_set)`) + trending (`ZREVRANGE`). Dedup por post_id.
  - Stage 2: **sem MMR nesta fase** — só score composto (sim LT, sim ST, β_trending) + filtro "seen" (Redis set `user:{id}:seen` TTL 7d).
  - Grava batch em `ranking_logs`.
  - Adiciona post_ids ao `seen`.
- Refatorar `App\Livewire\Pages\Feed\Index::render()` para consumir `FeedService::build()` quando `config('recommender.features.new_feed_service')` for `true`; senão, caminho legado.
- Criar comando `php artisan recommender:explain {user_id} {post_id}` que faz SELECT em `ranking_logs` e imprime breakdown.
- Atualizar `app:generate-post-embeddings` para enfileirar (conforme US-020), com flag `--sync` opcional para debug.
- Publicar o dashboard do Horizon em `/horizon` (sob middleware `auth` + gate adhoc).

**Fora de escopo (explícito):**
- Sinais negativos (hide/skip/report) — Fase 2.
- Dwell tracking — Fase 2.
- MMR diversidade — Fase 2.
- Cota por autor — Fase 2.
- Avoid embedding — Fase 2.
- Recency boost / exploration slot — Fase 3.
- Random baseline 1% — Fase 3.
- Cron diário de long-term — Fase 3 (nesta fase o long-term fica só no legado `users.embedding` via `CalculateCentroidJob`, que ainda roda em paralelo).

**Arquivos / áreas afetadas:**
- `app/Services/InteractionService.php` — **novo**.
- `app/Services/FeedService.php` — **novo**.
- `app/Enums/InteractionType.php` — **novo** (like, unlike, comment, uncomment, share, dwell, skip, hide, report).
- `app/Jobs/UpdateShortTermEmbeddingJob.php` — **novo** (fila `realtime`).
- `app/Jobs/ComputeTrendingPoolJob.php` — **novo** (fila `batch`).
- `app/Jobs/GeneratePostEmbeddingJob.php` — **modificado**: `onQueue('embeddings')`, retry config, persistir em `embedding_jobs`, preencher `embedded_at`/`embedding_model`.
- `app/Observers/PostObserver.php` — **modificado**: `dispatch_sync` → `dispatch()`.
- `app/Observers/LikeObserver.php` — **modificado**: delega a `InteractionService`, remove `CalculateCentroidJob` (mantém ainda em paralelo via flag — ver rollout).
- `app/Observers/CommentObserver.php` — **novo**.
- `app/Models/Comment.php` — **modificado**: `#[ObservedBy([CommentObserver::class])]`.
- `app/Livewire/Pages/Feed/Index.php` — **modificado**: branch por `config('recommender.features.new_feed_service')`.
- `app/Console/Commands/GeneratePostEmbeddings.php` — **modificado**: enfileirar por padrão, `--sync` opcional.
- `app/Console/Commands/RecommenderExplain.php` — **novo** (`recommender:explain {user_id} {post_id}`).
- `app/Console/Kernel.php` — **modificado**: cron `ComputeTrendingPoolJob` cada 15min.
- `config/horizon.php` — **novo** (via `horizon:install`).
- `config/queue.php` — **modificado**: `default` → `redis`.
- `composer.json` — **modificado**: `laravel/horizon` em `require`.

**Testes necessários:**
- Unitários: `InteractionService` (grava `interaction_events` + dispatcha job + seta dirty flag), `FeedService::build` (mock Redis, mock `post_embeddings`, verifica ordem e `ranking_logs`), `UpdateShortTermEmbeddingJob` (decay correto, normalização, fallback Postgres).
- Feature/Integration (Pest):
  - Criar post → `GeneratePostEmbeddingJob` enfileirado (não-sync).
  - Like em post → linha em `interaction_events` com weight=+1.0, `UpdateShortTermEmbeddingJob` enfileirado.
  - Comment em post → linha em `interaction_events` com weight=+2.5.
  - Feed de user com long-term + short-term → posts ordenados pelo score composto; `ranking_logs` povoado.
  - Feed de user novo (tudo null) → trending puro (cold start US-009).
  - Flag `new_feed_service=false` → `Feed\Index` cai no caminho legado (regressão).
  - `recommender:explain` retorna dados coerentes.
- Teste de carga: simular 200 requests concorrentes ao `FeedService::build()` com ambiente local (user com ~50 likes e trending populado). Alvo: p95 <200ms.

**Feature flag:** `recommender.features.new_feed_service`, `recommender.features.async_embedding`, `recommender.features.interaction_stream`, `recommender.features.ranking_logs`, `recommender.features.trending_pool`. Rollout feito em ordem: async_embedding → interaction_stream → trending_pool → new_feed_service → ranking_logs (última porque é só observabilidade).

**Rollout plan:**
1. Deploy do código com **todas as flags em `false`** → nenhuma mudança de comportamento.
2. Flip `async_embedding=true` em dev, validar criação de post. Depois prod.
3. Flip `interaction_stream=true`. `LikeObserver` passa a gravar em `interaction_events` **em paralelo** ao `CalculateCentroidJob` legado. Sem risco — append-only.
4. Flip `trending_pool=true`. Cron começa a popular Redis. Aguardar 30min para confirmar.
5. Flip `new_feed_service=true` em horário de baixo tráfego. Monitorar p95 por 1h. Kill-switch = flipar de volta.
6. Flip `ranking_logs=true` junto ou logo depois (só logging).

**Risco e mitigação:**

| Risco | Impacto | Probabilidade | Mitigação |
|---|---|---|---|
| Redis indisponível após swap de queue driver | Alto (filas param) | Baixa | `FeedService` tem fallback Postgres para short-term. Para filas: Horizon manda alertar; operador sobe Redis. |
| `ranking_logs` inflando disco rápido | Médio | Média | p95 de volume: ~60k linhas/dia no seed. Monitorar `SELECT pg_size_pretty(...)`. Se crescer fora da curva, truncar >30d manualmente (rollup automático é Fase 3). |
| `UpdateShortTermEmbeddingJob` saturando fila `realtime` em pico de likes | Médio | Média | Horizon throttle por fila (ex.: 20 workers `realtime`). Se backlog crescer >1000, investigar Gemini latency (não se aplica aqui, é Redis only). |
| `new_feed_service` com bug sutil de ordenação degradando CTR | Alto | Média | `ranking_logs` habilitado **antes** do feed novo no rollout inverte a ordem natural: assim os primeiros usuários do feed novo já ficam logados. Comparar CTR por posição antes/depois em janelas de 24h. Kill-switch rápido. |
| Horizon não arrancar em Sail | Baixo (bloqueio local) | Baixa | `horizon:install` gera supervisor; validar que `vendor/bin/sail artisan horizon` starta. |
| `CalculateCentroidJob` continua rodando e compete com novos jobs | Baixo (duplicação) | Alta | Aceito — `users.embedding` fica atualizado em paralelo, é fallback do `FeedService`. Remoção só após Fase 3. |

**Estimativa:** **8-12 dias** (distribui entre 2-3 devs baixa para 4-5 dias calendário).

**Critérios de "pronto":**
- [ ] Criar post retorna UI em <500ms (vs ~1-3s hoje).
- [ ] Dar like dispara `UpdateShortTermEmbeddingJob` visível no Horizon.
- [ ] Feed pós-like reflete mudança de short-term (teste manual: like em post de cachorro → próximos loads têm mais posts de cachorro).
- [ ] `ranking_logs` tem `perPage` linhas por feed request.
- [ ] `artisan recommender:explain 1 42` imprime breakdown do score.
- [ ] Cold start (user novo sem interação) recebe trending.
- [ ] p95 do `FeedService::build`: <200ms (medido em teste de carga).
- [ ] Todas as US listadas fecham seus critérios individuais.
- [ ] Suíte de testes passa: `vendor/bin/sail artisan test --compact`.

**Métricas a monitorar em produção:**
- Throughput da fila `embeddings` (alvo: consume > produção; alerta se backlog >100).
- p95 latência `FeedService::build` (alerta >250ms).
- Taxa de falha do `GeneratePostEmbeddingJob` (alerta >1%).
- CTR por posição em `ranking_logs` (linha de base para Fase 2).

**Dependência externa:** API Gemini (embedding de posts), Redis (short-term + trending + Horizon).

---

### Fase 2 — Sinais negativos, dwell e diversidade

**Objetivo:** capturar o conjunto completo de sinais descrito no `overview §6` — hide, skip, dwell, report, share — e fazer o ranker descartar/diversificar com base neles. Adicionar MMR e cota por autor ao Stage 2. Com isso o feed deixa de ser só "mais do que você curte" e passa a ser "mais do que você curte, menos do que você rejeita, com variedade".

**Valor entregue ao final:** o usuário tem ações explícitas para rejeitar conteúdo ("esconder", "denunciar") e o sistema aprende a evitar conteúdo semanticamente similar. Dwell passa a alimentar short-term mesmo sem like explícito. Feed não tem mais 10 posts do mesmo autor nem 10 posts do mesmo assunto. Share é reconhecido como endosso forte.

**User stories cobertas:** US-004, US-005, US-006, US-008, US-010, US-011, US-015, US-026.

**Escopo técnico:**
- Criar `App\Jobs\UpdateAvoidEmbeddingJob` (fila `realtime`): disparado por hide/report imediato e por regra de acumulação (≥5 skips do mesmo autor em 24h).
- Criar endpoint `POST /api/dwell` em `routes/api.php` — middleware `auth:sanctum|session` + `throttle:60,1`. Valida `[{post_id, dwell_ms, session_id}, ...]` e enfileira `RecordDwellEventsJob` na fila `ingest`.
- Criar `App\Jobs\RecordDwellEventsJob`: converte `dwell_ms` em weight conforme tabela do `overview §6`, grava em `interaction_events` (só quando weight ≠ 0), dispara `UpdateShortTermEmbeddingJob` para weights positivos.
- Client-side Alpine no `App\Livewire\Post\Card`: IntersectionObserver para tracking de dwell; batch send a cada 5 posts ou 10s; regra de skip (<1s dwell + 3 posts depois também visualizados).
- Botão **"Não quero ver isso"** no `PostCard` → ação Livewire `hide(Post $post)` → `InteractionService::record(..., InteractionType::Hide)` → adiciona ao set Redis `user:{id}:hidden` TTL 30d → `UpdateAvoidEmbeddingJob`.
- Botão **"Denunciar"** no `PostCard` → ação Livewire `report(Post $post, ?string $reason = null)` → `InteractionService::record(..., InteractionType::Report, metadata: ['reason' => ...])` → set `hidden` com TTL muito longo → `UpdateAvoidEmbeddingJob`.
- Botão **"Compartilhar"** no `PostCard` → ação Livewire `share(Post $post)` → gera URL + registra `InteractionService::record(..., InteractionType::Share, weight=+4.0)`. Comportamento concreto mínimo: copia o link do post para clipboard + toast.
- Estender `FeedService::build` Stage 2:
  - Após score composto, filtra candidatos com `cos(post, avoid) > config('recommender.avoid_threshold')` (default 0.85).
  - Aplica MMR iterativo (λ=0.7 configurável) até `perPage + buffer` itens.
  - Cota por autor: durante MMR, tracker de `author_count`, descarta excedentes nos top-20.

**Fora de escopo (explícito):**
- Cron diário de long-term — Fase 3.
- Random baseline 1% — Fase 3.
- Recency boost e exploration slot — Fase 3 (exceto se antecipados por folga de time — ver §2).
- Dashboards de métricas — Fase 3.
- UI de revisão de reports para operador — Fase 3 (US-021).

**Arquivos / áreas afetadas:**
- `app/Jobs/UpdateAvoidEmbeddingJob.php` — **novo**.
- `app/Jobs/RecordDwellEventsJob.php` — **novo**.
- `app/Http/Controllers/Api/DwellEventController.php` — **novo** (uma action `store`).
- `routes/api.php` — **novo** arquivo se ainda não existir, ou acrescentar rota.
- `resources/views/livewire/post/card.blade.php` — **modificado**: botões hide/share/report, bindings de IntersectionObserver via Alpine.
- `app/Livewire/Post/Card.php` (ou equivalente atual do card) — **modificado**: actions `hide`, `report`, `share`.
- `app/Services/FeedService.php` — **modificado**: filtro avoid + MMR + cota por autor no Stage 2.
- `app/Services/InteractionService.php` — **modificado**: passar a aceitar `metadata` e disparar `UpdateAvoidEmbeddingJob` quando tipo for `hide`/`report`.
- `resources/js/` (Alpine helper para IntersectionObserver batch) — **novo** pequeno módulo.

**Testes necessários:**
- Unitários: `UpdateAvoidEmbeddingJob` (média ponderada correta, normalização), `RecordDwellEventsJob` (mapping `dwell_ms`→weight), regra de skip (≥3 posts depois).
- Feature/Integration:
  - Hide em post → post some do feed + avoid atualizado + posts similares descem no ranking (testar com 3 posts semanticamente próximos, hidar 1, verificar que os outros 2 caem N+ posições).
  - Report em post → filtro permanente + evento registrado.
  - Dwell de 12s em post → `interaction_events` com weight=+1.0.
  - Dwell <1s + 3 posts depois → evento `skip` com weight=-0.3.
  - Share em post → weight=+4.0, `UpdateShortTermEmbeddingJob` disparado.
  - Feed com cota por autor: author X publicou 10 posts; top-20 tem ≤3 desse autor.
  - MMR: feed sem MMR vs com MMR — similaridade média entre pares diminui (teste `Pest` comparando duas chamadas de `FeedService::build` com flag on/off).
- Browser (Pest 4): smoke test do card — clicar em "Não quero ver isso" faz o card sumir sem full reload.
- Teste de carga: mesma régua da Fase 1, p95 <200ms mesmo com MMR ativo (MMR em 1k candidatos é <15ms em PHP, validar).

**Feature flag:** `recommender.features.negative_signals`, `recommender.features.mmr_diversity`. Ambas em `false` por default.

**Rollout plan:**
1. Deploy com flags `false` → UI mostra botões mas actions retornam no-op (para preservar contrato do PostCard sem ramificar template).
   - Alternativa mais limpa: esconder os botões via `@if(config('recommender.features.negative_signals'))` no Blade. Adotada.
2. Flip `negative_signals=true` em dev. Teste manual de hide/report/share/dwell.
3. Flip em prod. Monitorar `interaction_events` volume de tipos `dwell`/`skip` (alvo: ~5-10× mais linhas/dia que `like`).
4. Flip `mmr_diversity=true`. Monitorar intra-list diversity e CTR.

**Risco e mitigação:**

| Risco | Impacto | Probabilidade | Mitigação |
|---|---|---|---|
| Endpoint `/api/dwell` recebendo dados inflacionados/maliciosos | Médio (ruído no short-term) | Média | Throttle por usuário (60 req/min). Sanity check no job: `dwell_ms` clampado em [0, 600000]. `session_id` obrigatório e validado como alfanum. |
| MMR instável com pool <20 candidatos | Baixo (feed curto) | Baixa | Stage 1 com `ann_limit=500` garante pool cheio. Fallback: se pool <`perPage*2`, MMR degrada para score puro. |
| Avoid filtro agressivo esvaziando pool | Médio (feed curto) | Média | Se pool pós-avoid <`perPage`, aumenta `ann_limit` do Stage 1 e repete. Cap em 2x para não inflar latência. |
| IntersectionObserver com bug em navegador antigo | Baixo (só perde sinal) | Baixa | Feature-detect; se ausente, skip silenciosamente. Dwell é implícito, perder não quebra nada. |
| Subtração x filtro avoid mal implementado | Alto (feed degenera) | Baixa | Já está no `overview §7`: **filtro, não subtração**. Teste específico (feature test) que verifica o vetor do user não é alterado pelo avoid. |
| Usuário confunde hide com report | Baixo | Média | Textos claros: "Não quero ver isso" vs "Denunciar" com ícones distintos. Confirmation dialog em report. |

**Estimativa:** **7-10 dias**.

**Critérios de "pronto":**
- [ ] Hide → post some, posts similares caem.
- [ ] Report → post some permanentemente, linha em `interaction_events` com metadata.
- [ ] Share → weight +4 no short-term, link copiado.
- [ ] Dwell positivo atualiza short-term (verificar via `recommender:explain` antes/depois).
- [ ] MMR ativo reduz intra-list diversity em >15% (comparação feature test).
- [ ] Cota por autor: top-20 nunca tem >3 posts do mesmo autor.
- [ ] Endpoint `/api/dwell` passa teste de carga (1000 batches/min).
- [ ] Suíte de testes passa.

**Métricas a monitorar em produção:**
- Taxa de hide por 1k impressões (linha de base).
- Taxa de report por 1k impressões (esperado <0.5%).
- Intra-list diversity: mediana por feed request.
- Volume de `interaction_events` por tipo (alerta se `dwell` exceder 50k/dia no seed).
- p95 do `FeedService::build` (manter <200ms).

**Dependência externa:** Gemini (via `UpdateAvoidEmbeddingJob` lê `post_embeddings`, mas não gera novos). Redis intensificado (hidden sets, avoid lookups via Postgres direto).

---

### Fase 3 — Cron diário, recency, exploration, dashboards

**Objetivo:** trazer o long-term para fora do `CalculateCentroidJob` legado — `RefreshLongTermEmbeddingsJob` diário passa a ser a fonte de `users.long_term_embedding`. Adicionar random baseline 1% para validar que o ranker agrega valor. Operador ganha dashboard real de métricas (`US-022`) e comando para revisar reports (`US-021`). Exploration e recency boost fecham o Must+Should de consumidor e criador.

**Valor entregue ao final:** o long-term para de depender do `CalculateCentroidJob` legado — pode-se deletar `users.embedding` + `CalculateCentroidJob` (fica como débito consciente para fim da fase se tudo estiver estável por 7 dias). Instrutor tem dashboard quantitativo de "o ranker está melhorando?". Posts recentes têm chance, descoberta de conteúdo novo é explícita.

**User stories cobertas:** US-012, US-016, US-021, US-022, US-025, US-030, US-031, US-032.

**Escopo técnico:**
- Criar `App\Jobs\RefreshLongTermEmbeddingsJob` (fila `batch`): processa usuários no Redis set `long_term:dirty` em chunks de 100; agrega `interaction_events` dos últimos 180d com decay 30d; `UPDATE users SET long_term_embedding=..., long_term_updated_at=now()`.
- Cron diário 03:00 BRT em `app/Console/Kernel.php` → `RefreshLongTermEmbeddingsJob::dispatch()`.
- Criar `App\Jobs\RebuildShortTermFromEventsJob` (fila `realtime`): chamado quando `FeedService` detecta Redis miss + snapshot Postgres stale (>1h). Reconstrói a partir de 48h de `interaction_events`.
- `FeedService::build` estendido:
  - Exploration slot: 10% do pool do Stage 1 vem de `SELECT * FROM posts WHERE created_at > now() - interval '24 hours' ORDER BY random() LIMIT (0.1*pool)`.
  - Recency boost: score composto passa a ter `β_recency · 2^(-age_hours/24)`.
  - Random baseline: se `user.id % 100 == random_salt_do_dia`, bypass do ranker — ordem aleatória sobre trending pool, `source='random_baseline'` nos logs.
- Criar `App\Jobs\RollupInteractionMetricsJob` (fila `batch`) em cron horário: calcula métricas do `overview §9` a partir de `ranking_logs` e `interaction_events`, persiste em tabela `ranking_metrics_hourly` (migration nova).
- Criar `App\Livewire\Pages\Admin\Metrics` (Livewire full-page): cards para CTR@1, CTR@5, dwell médio, skip rate, Gini autores, cobertura, novelty, intra-list diversity. Período ajustável 1h/24h/7d. Comparação vs 7d anteriores.
- Criar `App\Livewire\Pages\Admin\Reports` (Livewire): lista posts ordenados por contagem de reports em 7d. Ações: ver detalhes, deletar (manual).
- Criar comando `php artisan recommender:reports` (fallback CLI do admin/Reports).
- Criar `database/migrations/2026_04_24_HHMMSS_create_ranking_metrics_hourly_table.php` (tabela rollup adiada pelo `03`).
- Gate middleware `auth` + `can:admin-recommender` (closure temporária: `fn ($user) => in_array($user->email, config('recommender.admin_emails'))`).
- **Cleanup do legado** (ao final da fase, se `long_term_cron` estável por 7d): remover `CalculateCentroidJob`, `LikeObserver` do `CalculateCentroidJob`, `users.embedding` via migration separada (`drop_legacy_embedding_from_users_table`).

**Fora de escopo (explícito):**
- Multi-interest / clusters k-means — Fase 4.
- Particionamento automático de `ranking_logs` — fora do plano (criar quando >50M linhas).
- Alerta externo (Slack/email) — `US-029` menciona canal pendente; fica como TODO dentro do próprio dashboard (métrica com flag de alerta visual).
- Moderação estruturada (workflow admin) — `US-011` foi confirmado como "só log".

**Arquivos / áreas afetadas:**
- `app/Jobs/RefreshLongTermEmbeddingsJob.php` — **novo**.
- `app/Jobs/RebuildShortTermFromEventsJob.php` — **novo**.
- `app/Jobs/RollupInteractionMetricsJob.php` — **novo**.
- `app/Console/Kernel.php` — **modificado**: 2 schedules (diário, horário).
- `app/Livewire/Pages/Admin/Metrics.php` — **novo**.
- `app/Livewire/Pages/Admin/Reports.php` — **novo**.
- `resources/views/livewire/pages/admin/metrics.blade.php` — **novo**.
- `resources/views/livewire/pages/admin/reports.blade.php` — **novo**.
- `app/Console/Commands/RecommenderReports.php` — **novo**.
- `app/Services/FeedService.php` — **modificado**: exploration slot, recency boost no score, branch random_baseline.
- `database/migrations/2026_04_24_*_create_ranking_metrics_hourly_table.php` — **novo**.
- (cleanup final) `app/Jobs/CalculateCentroidJob.php`, observer hook, `database/migrations/2026_04_*_drop_legacy_embedding_from_users_table.php` — **removidos/criados**.
- `config/recommender.php` — **modificado**: `admin_emails`, `recency_weight`, `exploration_fraction`.
- `routes/web.php` — **modificado**: rotas `Route::livewire('/admin/recommender/metrics', ...)` e `/admin/recommender/reports`.

**Testes necessários:**
- Unitários: `RefreshLongTermEmbeddingsJob` (decay correto sobre 180d, agregação ponderada), `RollupInteractionMetricsJob` (cada métrica bate com cálculo manual em fixture), random baseline (mod 100 determinístico).
- Feature/Integration:
  - Cron diário roda → usuário com dirty flag tem `long_term_updated_at` atualizado.
  - User dirty mas sem interação positiva → long_term permanece null.
  - Bucket baseline (user_id correto) recebe feed aleatório sobre trending.
  - `admin/recommender/metrics` renderiza sem erro; números batem com query manual.
  - Comando `recommender:reports` lista os 10 posts com mais reports.
  - Recency boost: post de 1h vs post de 48h com mesma similaridade → o de 1h aparece antes.
  - Exploration slot: feed de user estável contém ≥1 post de <24h em cada 10.
- Browser: smoke test em `/admin/recommender/metrics`.
- Teste de carga: `RollupInteractionMetricsJob` com 90d de ranking_logs sintético → <2min.

**Feature flag:** `recommender.features.long_term_cron`, `recommender.features.random_baseline`. Recency e exploration entram direto (são ajustes de pesos — se valor zero na config, ficam neutros, não precisam flag separada).

**Rollout plan:**
1. Deploy com flags `false`. Admin pages visíveis só pra `admin_emails`.
2. Flip `long_term_cron=true`. Cron roda à noite; no dia seguinte, verificar `SELECT count(*) FROM users WHERE long_term_updated_at > now() - interval '25 hours'`.
3. Validar por 7 dias que `users.long_term_embedding` fica fresh vs `users.embedding` legado; decidir cutover.
4. Flip `random_baseline=true`. Monitorar que bucket é ~1% (tolerância 0.8-1.2%).
5. Depois de 14d com flags estáveis, criar a migration de cleanup do legado (remover `users.embedding` + `CalculateCentroidJob`). PR separado.

**Risco e mitigação:**

| Risco | Impacto | Probabilidade | Mitigação |
|---|---|---|---|
| `RefreshLongTermEmbeddingsJob` explode memória ao agregar 180d de eventos | Médio | Média | Chunks de 100 usuários. Por usuário, query com `LIMIT 1000` nos eventos mais recentes (sanity cap). Se exceder, log e skip. |
| Random baseline 1% prejudica UX de um subgrupo de usuários | Médio | Alta | Salt rotativo diário — ninguém fica no bucket permanentemente. Banner "Você está num experimento" se quiser transparência. |
| Dashboard Livewire lento (full scan de `ranking_logs`) | Médio | Alta | Dashboard lê só `ranking_metrics_hourly` (pré-agregado). Janela de 7d = 168 linhas × métricas. Trivial. |
| Migração `DROP COLUMN users.embedding` quebra algo que ainda referencia | Alto | Baixa | Grep full codebase antes. Adiar se houver referência — custo de manter coluna é desprezível. |
| Long-term calculado diverge muito do centroide legado (UX instável) | Médio | Média | Cutover gradual: usuários sem `long_term_embedding` continuam usando `users.embedding` como fallback no `FeedService` (já previsto na Fase 1). Diferença natural vai diluir à medida que cron roda. |

**Estimativa:** **6-8 dias**.

**Critérios de "pronto":**
- [ ] Cron noturno atualiza `long_term_embedding` de todos os usuários dirty.
- [ ] `admin/recommender/metrics` mostra CTR@1 dos últimos 7d.
- [ ] Random baseline produz ~1% das impressões.
- [ ] Recency boost diferencia posts de 1h vs 48h no mesmo score base.
- [ ] Posts de <24h aparecem em ≥10% das impressões (novelty).
- [ ] Rebuild de short-term roda em <2s quando Redis miss acontece.
- [ ] Legado (`users.embedding` + `CalculateCentroidJob`) removido via migration separada após 14d de estabilidade.

**Métricas a monitorar em produção:**
- Duração do `RefreshLongTermEmbeddingsJob` (alerta >45min para 300 users).
- Delta CTR: ranker vs baseline (alvo: ranker >10% melhor em CTR@1; se <0, rollback).
- Novelty: fração de posts <24h no feed (alvo ≥10%).
- Número de usuários com `long_term_updated_at > 48h` e `dirty=true` (alerta se >10).

**Dependência externa:** Gemini (indireto via `post_embeddings`), Redis, Postgres intensificado (cron pesado).

---

### Fase 4 — Multi-interest clustering (roadmap)

**Objetivo:** transformar a representação do usuário de um único vetor long-term em **N clusters de interesse disjuntos**. O candidate generation passa a consultar ANN com cada centroide e mesclar os resultados. Resolve o problema de "média de futebol + culinária aterrissa num ponto que não é nem um nem outro" descrito no `overview §7`.

**Valor entregue ao final:** usuários com interesses múltiplos recebem feed que cobre os vários interesses em vez de colapsar na média. Ganho esperado de cobertura + diversidade sem sacrificar relevância.

**User stories cobertas:** nenhuma explícita no `02` — roadmap do `overview §4`.

**Escopo técnico:**
- Criar `App\Jobs\RefreshInterestClustersJob` (fila `batch`) em cron diário depois do long-term: k-means sobre embeddings de posts com interação positiva do usuário. K ∈ [3, 7] determinado por silhouette score ou fixo K=5 como baseline.
- Biblioteca de k-means: PHP puro (pequeno port) — decisão adotada para não adicionar microsserviço Python. K=5 em 100-500 vetores é <100ms em PHP.
- `FeedService::build` Stage 1: se `user` tem clusters, em vez de 1 query ANN, faz K queries (`LIMIT ann_limit/K` cada) e deduplica. Se não tem clusters, fallback para long-term único (comportamento da Fase 3).
- Fallback explícito: se K queries paralelas têm latência >100ms combinada, cai para single-query e logga warning.

**Fora de escopo (explícito):**
- Explicação visual de "de que cluster veio este post" — fica dentro do `explain` mas não é UI user-facing.
- Ajuste dinâmico de K por usuário baseado em dispersão de interesses — heurística fica com K fixo.

**Arquivos / áreas afetadas:**
- `app/Jobs/RefreshInterestClustersJob.php` — **novo**.
- `app/Services/KMeansClusterer.php` — **novo** (ou via lib externa se encontrarmos uma madura em PHP).
- `app/Services/FeedService.php` — **modificado**: branch multi-cluster no Stage 1.
- `app/Models/UserInterestCluster.php` — **novo** (Eloquent model sobre a tabela já criada na Fase 0).
- `app/Models/User.php` — **modificado**: relação `interestClusters()`.
- `app/Console/Kernel.php` — **modificado**: cron `RefreshInterestClustersJob`.

**Testes necessários:**
- Unitários: `KMeansClusterer` (k=2 sobre 2 clusters claramente disjuntos converge em <N iters).
- Feature: usuário com likes em futebol E em culinária → 2 clusters distintos após rodar job; feed mescla os dois (intra-list diversity maior que single-cluster).
- Teste de carga: `RefreshInterestClustersJob` para 300 users com avg 30 posts cada → <5min total.

**Feature flag:** `recommender.features.multi_interest`. Default `false`.

**Rollout plan:**
1. Deploy com flag `false`.
2. Flip em dev, rodar cron manualmente, inspecionar `user_interest_clusters` — centroides fazem sentido?
3. Flip em 1 usuário de teste em prod; validar feed com `recommender:explain`.
4. Flip global. Monitorar latência do Stage 1 (K queries paralelas devem rodar em <60ms).

**Risco e mitigação:**

| Risco | Impacto | Probabilidade | Mitigação |
|---|---|---|---|
| K-means instável (clusters mudam muito entre execuções) | Médio (feed inconsistente) | Média | K-means com seed fixo por usuário (`user_id` como seed). Monitorar drift com métrica "cos entre centroides de dia-1 e dia" — alvo >0.9. |
| Latência de K queries ANN paralelas > que single query | Médio | Baixa | Benchmark local antes. Fallback documentado. `pgvector` HNSW é indexed — K queries em paralelo se comportam bem. |
| Usuários com poucos interação não justificam K clusters | Baixo (degrada grácil) | Alta | Regra: se total de posts positivos <3K (onde K é o K escolhido), job grava só 1 cluster (=long-term). Fallback natural. |
| K-means em PHP lento em escala | Baixo (workshop) | Baixa | Para 300 users, suficiente. Acima de 10k users ativos, considerar microsserviço Python. |

**Estimativa:** **5-7 dias**.

**Critérios de "pronto":**
- [ ] Usuário com interesses disjuntos tem ≥2 clusters após cron.
- [ ] Feed mostra posts de múltiplos clusters misturados (validação qualitativa + intra-list diversity).
- [ ] Latência Stage 1 não regride vs Fase 3.
- [ ] Fallback para single-vector funciona quando user tem <15 posts positivos.

**Métricas a monitorar em produção:**
- Intra-list diversity (alvo: +5-10% vs Fase 3).
- CTR@1 (alvo: não regride vs Fase 3; idealmente sobe em usuários com interesses múltiplos).
- Latência do `FeedService::build` (alerta se p95 >220ms).

**Dependência externa:** Gemini (lê `post_embeddings`).

---

## 4. Cross-cutting concerns

Temas que atravessam múltiplas fases e precisam ser geridos continuamente:

### 4.1. LGPD / privacidade

- `interaction_events` guarda comportamento individual por usuário. É dado pessoal pela LGPD.
- Request de exclusão de conta (não coberto no `02`, mas o app deveria ter): precisa cascade em `interaction_events`, `ranking_logs`, `users.*_embedding`, `user_interest_clusters`, Redis keys `user:{id}:*`. Cascade em FK já está em todas as novas tabelas. Redis precisa de job `PurgeUserRedisDataJob` (não coberto — débito assumido).
- Embeddings do usuário (`users.long_term_embedding`) são dados pessoais derivados — rege pelos mesmos direitos. Fácil de apagar (UPDATE NULL), presta atenção na exportabilidade se solicitada.

### 4.2. Custo da API Gemini

- 1 chamada por post novo (Fase 1) = baseline aceitável.
- Backfill (US-020) com `--force` pode disparar 1250 chamadas de uma vez — usar `Redis::throttle` conforme US-018 para não estourar quota.
- Rate limit padrão Gemini Embedding 2 Preview: confirmar antes de produção real (para workshop é quota generosa).
- Sem chamadas ao Gemini em hot path (request do feed) — mantido como invariante desde Fase 1.

### 4.3. Gerenciamento de chaves

- API key do Gemini em `.env` (já é). Nenhuma key nova nas fases.
- Rotação não coberta (workshop).

### 4.4. Segurança

- Endpoint `/api/dwell` (Fase 2) é público autenticado — throttle + validação de `dwell_ms` range.
- Admin pages (Fase 3) sob gate custom. Sem role model admin — aceito (documentado no `02 US-021`).

### 4.5. Performance de PG vector

- Monitorar `SELECT pg_size_pretty(pg_relation_size('post_embeddings'))` ao longo das fases.
- Se index HNSW ficar >1GB em RAM, considerar `pgvectorscale` (DiskANN) — só acontece >10⁶ posts, irrelevante no workshop.

### 4.6. Dívidas técnicas aceitas ao longo do plano

Consolidadas em `§5`.

---

## 5. Dívidas técnicas aceitas

Lista explícita do que **sabemos** que fica subótimo e **não** corrigimos dentro deste plano:

1. **`GeminiEmbeddingService` continua usando `Http::post` direto**, mesmo com `laravel/ai` no `composer.json`. Justificativa: migrar adiciona risco sem benefício funcional. Débito: quando `laravel/ai` ganhar features (retries nativos, streaming), reavaliar.
2. **`users.embedding` coexiste com `long_term_embedding`** até o final da Fase 3. Duplicação controlada, não crítica.
3. **FK `post_embeddings.post_id` sem cascade** (§1.3 do `03`). Não bloqueante até alguém deletar post em massa.
4. **Retenção de `interaction_events` e `ranking_logs` não automatizada**. Manual purge até volume doer. Operador executa `DELETE WHERE created_at < now() - interval '180 days'` ad-hoc.
5. **`RollupInteractionMetricsJob` grava em tabela plana** sem particionamento. Refatorar para Timescale ou partitioned table só quando dashboards ficarem lentos.
6. **Client-side dwell tracking não tem batching resiliente** (se request falha, dwell perdido). Aceito — dwell é sinal implícito, perder alguns não quebra ranking.
7. **Multi-interest clustering em PHP puro** (Fase 4). Não escala para 10⁵+ users ativos. Débito para microsserviço Python se produto crescer.
8. **Sem alerta externo (Slack/PagerDuty)**. Alertas ficam visuais no dashboard `admin/recommender/metrics`. Operador precisa olhar.
9. **Admin pages sob `in_array(email, ...)`**, não sob role real. Workshop-level; débito claro.
10. **Feature flag é config estática**, não runtime. Flip requer `vendor/bin/sail artisan config:cache` + restart Horizon. Aceito (toggle só pelo operador).
11. **Random baseline salt rotativo diário mas sem persistência de histórico** — se alguém perguntar "em que dia o user 42 estava no baseline?", não temos resposta. Débito se operador quiser auditoria fina.
12. **Sem job de purge do Redis para conta deletada**. Débito LGPD — §4.1.

---

## 6. Critérios de ida para a próxima fase

Cada fase deve atender **todos** os critérios abaixo antes de começar a próxima. Se algum quebrar, o caminho é corrigir na fase atual ou ficar ali até estabilizar.

### Ao sair da Fase 0
- [ ] Migrations 1-7 aplicadas em prod sem rollback.
- [ ] `config/recommender.php` existe com todos os defaults.
- [ ] `RecommendationDemoSeeder` hidratou `interaction_events` (verificar count >0).
- [ ] Suíte de testes passa.
- [ ] Regressão manual do feed: comportamento idêntico ao pré-deploy.
- **Sinal verde para Fase 1:** nenhum bug reportado por 48h após deploy.

### Ao sair da Fase 1
- [ ] Todas as flags da Fase 1 em `true` em prod por ≥7 dias.
- [ ] p95 `FeedService::build` <200ms observado em `ranking_logs` (instrumentar o próprio service com `microtime`).
- [ ] Zero posts entrando em `failed_jobs` nos últimos 7 dias para `GeneratePostEmbeddingJob`.
- [ ] Horizon verde (todas as filas sem backlog persistente).
- [ ] CTR@1 medido em `ranking_logs` ≥ CTR@1 da versão cronológica (linha de base pré-Fase 1 — pode exigir feature branch que mede o antigo via `ranking_logs` em shadow mode; se custo for alto, aceitar medida subjetiva).
- **Sinal verde para Fase 2:** operador confirma que `recommender:explain` ajuda em queixas reais.

### Ao sair da Fase 2
- [ ] Flags da Fase 2 em `true` por ≥7 dias.
- [ ] Intra-list diversity média caiu ≥15% vs Fase 1.
- [ ] Hide reduz presença de posts similares no feed seguinte (validação qualitativa + um teste offline em `ranking_logs` comparando antes/depois de 5 hides).
- [ ] `/api/dwell` recebe volume coerente sem erros 5xx (verificar laravel log).
- [ ] Nenhuma regressão de p95 (`FeedService::build` ainda <200ms).
- **Sinal verde para Fase 3:** taxa de hide estabiliza (<1%/1000 impressões) — indicando que usuários estão encontrando conteúdo que gostam.

### Ao sair da Fase 3
- [ ] Cron noturno rodando sem falha por ≥7 dias.
- [ ] Dashboard `admin/recommender/metrics` mostra tendências estáveis.
- [ ] Random baseline confirma ranker >10% melhor em CTR@1.
- [ ] Legado (`users.embedding` + `CalculateCentroidJob`) removido via PR separado — Fase 3 considerada "fechada" só após esse cleanup.
- **Sinal verde para Fase 4 (se priorizada):** evidência nos dados de que usuários com perfil claramente multi-interesse têm CTR baixo versus mono-interesse — aí multi-interest tem tese forte.

---

## 7. Resumo final

- **Fases 0→3 são o produto mínimo "honesto"** — fecham todos os `Must` e `Should` do `02`, deixam observabilidade real e encerram a dívida do centroide legado. Esforço: ~23-33 dias ideais.
- **Fase 4 é opcional** e só se paga com evidência. Se a Fase 3 mostrar que CTR está alto e usuários estão satisfeitos, Fase 4 vira roadmap de produto, não obrigação.
- **Ordem é sequencial** por dependência lógica, mas dentro da Fase 1 e Fase 2 há paralelização boa com 2-3 devs.
- **Nenhuma mágica**: cada risco tem mitigação concreta ou é aceito como débito documentado.
- **Rollback é sempre possível** via flip de config flag (sem revert de código), exceto a remoção final do legado (Fase 3 cleanup), que é uma migration com `down()` destrutivo — por isso fica num PR separado após janela de 14d.
