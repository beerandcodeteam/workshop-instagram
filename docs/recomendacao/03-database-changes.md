# Mudanças no Banco de Dados — Sistema de Recomendação

> Documento de design e implementação das mudanças de schema necessárias para os fluxos descritos em `01-overview.md` e cobertos pelas histórias de `02-user-stories.md`. As migrations propostas estão listadas na seção 7 e os arquivos reais foram criados em `database/migrations/` (ver listagem final).
>
> **Decisões-chave confirmadas** (de rodadas anteriores e desta tarefa):
> - O embedding do post **permanece em `post_embeddings`** (tabela 1:1 já existente com índice HNSW). Apenas adicionamos metadados. Não replicamos em `posts.embedding`.
> - `users.embedding` (centroide legado do MVP atual) é **mantido em paz**. Colunas novas (`long_term_embedding`, `short_term_embedding`, `avoid_embedding`) são adicionadas **ao lado**; o corte do legado fica para migration futura após cutover.
> - Dimensão: **`vector(1536)`** para todos os vetores. Justificativa na §3.1.
> - **Sem A/B testing / feature flags na v1** → a tabela `recommendation_experiments` sugerida pelo escopo da tarefa **não é criada**. Pesos vivem em `config/recommender.php`.
> - Tabela de eventos segue o nome do overview: **`interaction_events`** (não `post_interactions`).

---

## 1. Auditoria do schema atual

Levantamento feito direto das migrations em `database/migrations/` em `2026-04-22`. Tabelas não relevantes para recomendação (`password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `post_types`, `post_media`) estão fora do escopo desta auditoria.

### 1.1. `users`

```
id                  bigint PK
name                varchar(255)
email               varchar(255) UNIQUE
email_verified_at   timestamp NULL
password            varchar(255)
remember_token      varchar(100) NULL
created_at/updated_at
embedding           vector(1536) NULL     -- legado do MVP (média simples de likes)
```

- Coluna `embedding` adicionada em `2026_04_22_233556_add_embedding_to_users_table.php`.
- **Bug identificado naquela migration**: o `down()` está vazio (`Schema::table('users', function ($table) { /* ... */ });`), ou seja, rollback não remove a coluna. Não vamos corrigir a migration antiga (regra do projeto: nunca modificar migration existente); apenas documentar.
- Escrita pelo `App\Jobs\CalculateCentroidJob` (recalcula média dos embeddings de posts curtidos).
- Lida por `App\Livewire\Pages\Feed\Index::render()` para o `ORDER BY <=>`.

### 1.2. `posts`

```
id                  bigint PK
user_id             bigint FK → users.id
post_type_id        bigint FK → post_types.id
body                text NULL
created_at/updated_at

Índices: created_at, user_id, post_type_id
```

- Nenhuma referência a embedding nesta tabela. O relacionamento com embedding é via `Post::embedding()` → `hasOne(PostEmbedding::class)`.

### 1.3. `post_embeddings`

```
id           bigint PK
post_id      bigint FK → posts.id   (sem cascade on delete explícito!)
embedding    vector(1536) NOT NULL
created_at/updated_at

Índices:
  embedding_hnsw_idx USING hnsw (embedding vector_cosine_ops)
```

- Criada em `2026_04_22_225046_create_post_embeddings_table.php`.
- HNSW já existe com `vector_cosine_ops`. **Não precisa recriar.**
- **Ponto a corrigir**: o FK é `foreignId('post_id')->constrained()` sem `->cascadeOnDelete()`. Se um `Post` for deletado (US-3.5 do app base), haverá erro de FK violation. Tratamos como dívida técnica no plano de migração (§4).
- **Sem coluna de auditoria**: não sabemos *quando* nem *com qual modelo* foi gerado o embedding — problema real quando o modelo mudar ou precisar invalidar em massa.

### 1.4. `likes`

```
id                  bigint PK
user_id             bigint FK
post_id             bigint FK (cascadeOnDelete)
created_at/updated_at

UNIQUE (user_id, post_id) — um like por usuário por post
INDEX post_id
```

- Tabela de domínio. Mantida como está — é entidade de negócio (toggle, unique) com UI própria.
- Observer `LikeObserver` dispara `CalculateCentroidJob` (legado). Será refatorado para alimentar `interaction_events` (US-002).

### 1.5. `comments`

```
id                  bigint PK
user_id             bigint FK
post_id             bigint FK (cascadeOnDelete)
body                text
created_at/updated_at

INDEX (post_id, created_at), INDEX user_id
```

- Tabela de domínio. Sem observer hoje. Receberá `CommentObserver` novo (US-003).

### 1.6. O que NÃO existe

Lacunas identificadas para sustentar o que o overview descreve:

- Tabela de eventos de interação (unificada, append-only) — não existe.
- Sinais negativos de qualquer natureza — não existe (hide, skip, report).
- Dwell time — não existe.
- Compartilhamento — não existe (nem entidade, nem evento).
- `users.long_term_embedding` / `short_term_embedding` / `avoid_embedding` — não existem.
- `users.long_term_updated_at` / `short_term_updated_at` — não existem.
- Metadados de embedding em `post_embeddings` (`embedded_at`, `embedding_model`) — não existem.
- Tabela de logs de ranking (uma linha por candidato servido) — não existe.
- Tabela de clusters de interesse do usuário — não existe.
- Tabela de auditoria de jobs de embedding — não existe (o Laravel tem só `failed_jobs`).
- `seen`, `hidden`, `dirty:long_term` são conceitos de **Redis**, não do schema. Ficam fora desta migração (são responsabilidade do runtime).

---

## 2. Gap analysis

| Gap | Impacto se não resolver | Resolução |
|---|---|---|
| Sem tabela única para todos os sinais | Impossível reconstruir `long_term` por decay ponderado, que depende de agregar like+comment+share+dwell em uma query só | Criar `interaction_events` (§3.3) |
| Sem colunas de vetor múltiplo em `users` | `FeedService` não consegue ler long/short/avoid separados | Adicionar 3 colunas + timestamps em `users` (§3.2) |
| Sem metadados em `post_embeddings` | Não dá pra identificar qual modelo gerou cada vetor; invalidação em massa precisa de heurística | Adicionar `embedded_at`, `embedding_model` em `post_embeddings` (§3.2) |
| Sem tabela de `ranking_logs` | US-017 (debug "por que esse post?") impossível de atender em dados reais | Criar `ranking_logs` (§3.3) |
| Sem coverage de clusters | US de multi-interest (roadmap) sem tabela de apoio | Criar `user_interest_clusters` como schema-only (§3.3) |
| Sem audit de jobs de embedding além de `failed_jobs` | Métricas de `GeneratePostEmbeddingJob` (§9 overview) ficam incompletas | Criar `embedding_jobs` minimalista (§3.3) |
| `post_embeddings.post_id` sem cascade | Deletar post gera erro de FK | Documentar dívida (§4), não bloqueante |
| `users.embedding` legado | Duplicação durante transição; nenhum risco de dados | Manter, deprecar em migration futura |

---

## 3. Mudanças propostas

### 3.1. Extensões PostgreSQL

| Extensão | Estado atual | Ação |
|---|---|---|
| `vector` (pgvector) | Já habilitada via `Schema::ensureVectorExtensionExists()` na migration `2026_04_22_225046_...`. | Reaproveitar — nenhuma migration extra. Todas as nossas novas migrations com tipos vetoriais continuam assumindo `vector` ativa. |
| `pgvectorscale` (DiskANN) | Não habilitada. | **Não habilitar**: DiskANN faz sentido a partir de O(10⁶) vetores com dataset maior que RAM. Temos <10⁵ posts no seed, HNSW in-memory é ótimo aqui. Documentar como consideração para quando escalar. |

#### Decisão de tipo: `vector(1536)` vs `halfvec(3072)`

Avaliamos as alternativas:

| Tipo | Dimensão | Bytes/row | Precisão | HNSW ok? |
|---|---:|---:|---|---|
| `vector(1536)` | 1536 | 6144 (4 bytes × 1536) | fp32 | **Sim** (limite HNSW = 2000 dims para `vector`) |
| `halfvec(3072)` | 3072 | 6144 (2 bytes × 3072) | fp16 | Sim (limite = 4000 dims para `halfvec`) |

**Escolhemos `vector(1536)`** por cinco razões:

1. **`GeminiEmbeddingService` já fixa `output_dimensionality=1536`** (`app/Services/GeminiEmbeddingService.php`). Mudar exigiria alterar a configuração do modelo e re-embedar todo o catálogo.
2. **Compatibilidade com o existente**: `post_embeddings.embedding` já é `vector(1536)`. Manter o mesmo tipo em todas as colunas vetoriais simplifica queries (sem cast entre tipos).
3. **Storage equivalente**: 6144 bytes nos dois casos — halfvec só compensa quando a dimensão é maior.
4. **Precisão**: fp32 elimina risco de artefatos numéricos de fp16 em operações agregadas (soma ponderada de 180 dias de eventos pode gerar acumulação de erro; fp32 é seguro).
5. **Limite HNSW**: 1536 está confortavelmente abaixo do limite de 2000 do tipo `vector` — sem necessidade de `halfvec` para indexar.

### 3.2. Alterações em tabelas existentes

#### `users` — adicionar colunas de embedding multi-vetor

| Coluna | Tipo | Nullable | Default | Justificativa |
|---|---|---|---|---|
| `long_term_embedding` | `vector(1536)` | SIM | NULL | Vetor estável do usuário (half-life 30d). Overview §4. NULL = sem interações positivas ainda. |
| `short_term_embedding` | `vector(1536)` | SIM | NULL | Snapshot do vetor de sessão (half-life 6h). Redis é a source-of-truth; esta coluna é fallback para cold restart. Overview §5.6 / §7. |
| `avoid_embedding` | `vector(1536)` | SIM | NULL | Vetor agregado de sinais negativos fortes. Overview §4. Usado como filtro por distância (US-004). |
| `long_term_updated_at` | `timestamp` | SIM | NULL | Controle de staleness. Usado pelo cron (US-025) pra priorizar usuários com dados antigos. |
| `short_term_updated_at` | `timestamp` | SIM | NULL | Usado pela lógica de `RebuildShortTermFromEventsJob` (US-032): se `now() - short_term_updated_at > 1h` e cache Redis miss, reconstrói. |

**Mantido**: `users.embedding` (coluna legado). Será deprecada em migration separada após cutover do novo pipeline. Não há urgência.

**Histórias motivadoras**: US-001, US-004, US-024, US-025, US-026, US-032.

#### `post_embeddings` — adicionar metadados de embedding

| Coluna | Tipo | Nullable | Default | Justificativa |
|---|---|---|---|---|
| `embedded_at` | `timestamp` | NÃO | `CURRENT_TIMESTAMP` | Quando o vetor foi gerado. Diferente de `created_at` porque `created_at` é gerenciado pelo Eloquent e reflete o INSERT; se re-embedarmos (US-020 `--force`), `embedded_at` é atualizado e `created_at` permanece. |
| `embedding_model` | `varchar(64)` | NÃO | `'gemini-embedding-2-preview:1536'` | Identifica modelo + dimensão. Quando o Gemini subir versão, usamos este campo para filtrar vetores obsoletos e agendar re-embedding progressivo. |

Para linhas existentes, ambas recebem o default na migration (ver §7, migration 2).

**Histórias motivadoras**: US-020, US-023 (auditabilidade).

### 3.3. Novas tabelas

#### `interaction_events`

```sql
CREATE TABLE interaction_events (
    id                BIGSERIAL PRIMARY KEY,
    user_id           BIGINT NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    post_id           BIGINT NOT NULL REFERENCES posts (id) ON DELETE CASCADE,
    type              VARCHAR(20) NOT NULL,            -- like, unlike, comment, uncomment,
                                                      -- share, dwell, skip, hide, report
    weight            DOUBLE PRECISION NOT NULL,       -- peso já derivado (ver tabela §6 do overview)
    dwell_ms          INTEGER,                         -- só para type in ('dwell', 'skip')
    session_id        VARCHAR(64),                     -- sessão do Livewire; permite análise intra-sessão
    metadata          JSONB,                           -- extensível: {report_reason: '...', source: 'feed'}
    created_at        TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX interaction_events_user_id_created_at_idx
    ON interaction_events (user_id, created_at DESC);

CREATE INDEX interaction_events_post_id_type_idx
    ON interaction_events (post_id, type);

CREATE INDEX interaction_events_type_created_at_idx
    ON interaction_events (type, created_at DESC)
    WHERE type IN ('like', 'comment', 'share');
```

**Propósito**: stream append-only de todos os sinais (US-002, US-003, US-004, US-005, US-006, US-010, US-011). Alimenta os jobs `RefreshLongTermEmbeddingsJob` (US-025), `RebuildShortTermFromEventsJob` (US-032) e o trending pool (US-027).

**Relação com `likes` e `comments`**: paralelismo intencional. `likes` e `comments` continuam sendo **tabelas de domínio** (UI usa, unique constraint garante integridade). `interaction_events` é a **camada analítica**. Observers sincronizam.

**Crescimento estimado**:
- 300 usuários do seed, ~5 interações/dia/usuário → ~1500 linhas/dia → ~550k/ano.
- Se ampliar para 10k usuários ativos → ~18M/ano.
- Cada linha ~150 bytes (com JSONB). Ano 1 ≈ 2.7 GB para 10k usuários.
- **Política de retenção** (decisão pendente overview §11 item 10): sugerido dropar ou arquivar `dwell`/`skip` com >180d; manter `like`/`comment`/`share`/`hide`/`report` mais tempo.

**Histórias motivadoras**: US-002, US-003, US-004, US-005, US-006, US-010, US-011, US-025.

#### `ranking_logs`

```sql
CREATE TABLE ranking_logs (
    id                BIGSERIAL PRIMARY KEY,
    user_id           BIGINT NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    post_id           BIGINT NOT NULL REFERENCES posts (id) ON DELETE CASCADE,
    session_id        VARCHAR(64),
    position          SMALLINT NOT NULL,               -- 1-based
    source            VARCHAR(20) NOT NULL,            -- ann, trending, recency, random_baseline
    sim_long_term     REAL,                            -- similaridade cos contra long_term (-1..1)
    sim_short_term    REAL,
    trending_score    REAL,                            -- normalizado (0..1)
    recency_score     REAL,                            -- normalizado (0..1)
    mmr_penalty       REAL,                            -- penalidade aplicada no MMR
    final_score       REAL NOT NULL,                   -- score final após MMR
    served_at         TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX ranking_logs_user_post_served_idx
    ON ranking_logs (user_id, post_id, served_at DESC);

CREATE INDEX ranking_logs_served_at_idx
    ON ranking_logs (served_at DESC);

CREATE INDEX ranking_logs_source_idx
    ON ranking_logs (source);
```

**Propósito**: uma linha por candidato ranqueado servido (US-028). Base para: debug "por que esse post?" (US-017), dashboards de métricas (US-022, US-031) e análise offline do random baseline (US-030).

**Decisões técnicas**:
- `REAL` (4 bytes) em vez de `DOUBLE PRECISION` para os scores — precisão suficiente e metade do storage. `final_score` é `REAL` também; `NOT NULL` apenas no `final_score` e nos campos contextuais (id, user, post, position, source, served_at) — os componentes do score são NULL-ables porque nem toda fonte popula todos (ex: `source='trending'` não tem `sim_short_term` relevante, só `trending_score`).
- Sem FK strict em `session_id` (nenhuma tabela `sessions` de domínio — a do Laravel é de HTTP session, vida efêmera).
- **Particionamento por mês**: considerado, não adotado em v1. Se crescimento ultrapassar 100M/mês, converter em tabela particionada por `served_at`.

**Crescimento estimado**:
- Cada feed request grava ~10 linhas. Com 300 usuários × 20 requests/dia → 60k linhas/dia.
- Cada linha ~90 bytes → ~5.4 MB/dia, ~2 GB/ano.
- A 10k usuários ativos, ~66 GB/ano — ponto em que particionamento + purga de >90d fica obrigatório.

**Histórias motivadoras**: US-017, US-022, US-028, US-030, US-031.

#### `user_interest_clusters` (roadmap / avançado)

```sql
CREATE TABLE user_interest_clusters (
    id                BIGSERIAL PRIMARY KEY,
    user_id           BIGINT NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    cluster_index     SMALLINT NOT NULL,               -- 0..K-1 (K ∈ [3, 7])
    centroid          VECTOR(1536) NOT NULL,
    member_count      INTEGER NOT NULL DEFAULT 0,      -- quantos posts contribuíram
    updated_at        TIMESTAMPTZ NOT NULL DEFAULT now(),

    UNIQUE (user_id, cluster_index)
);

CREATE INDEX user_interest_clusters_user_id_idx
    ON user_interest_clusters (user_id);
```

**Propósito**: suportar multi-interest (overview §4, roadmap). K-means sobre os embeddings de posts positivamente interagidos por usuário, N centroides guardados aqui.

**Criada agora mesmo sem ser consumida ainda?** Sim, é decisão explícita do usuário ("todas as funcionalidades de uma vez"). Tabela fica criada; `RefreshInterestClustersJob` e o uso no candidate generation ficam como próxima fase de código.

**Sem HNSW aqui**: o candidate generation, quando consumir, vai iterar os K centroides do usuário (baixa cardinalidade) e fazer K queries ANN sobre `post_embeddings` — o índice HNSW relevante é o que já existe em `post_embeddings.embedding`.

**Histórias motivadoras**: overview §4 (roadmap); não há US específica por ora.

#### `embedding_jobs` (audit leve)

```sql
CREATE TABLE embedding_jobs (
    id                BIGSERIAL PRIMARY KEY,
    post_id           BIGINT NOT NULL REFERENCES posts (id) ON DELETE CASCADE,
    status            VARCHAR(16) NOT NULL,            -- pending, success, failed
    attempts          SMALLINT NOT NULL DEFAULT 0,
    embedding_model   VARCHAR(64),                     -- modelo usado (igual a post_embeddings.embedding_model)
    last_error        TEXT,
    started_at        TIMESTAMPTZ,
    finished_at       TIMESTAMPTZ,
    created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at        TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX embedding_jobs_post_id_idx
    ON embedding_jobs (post_id);

CREATE INDEX embedding_jobs_status_idx
    ON embedding_jobs (status)
    WHERE status IN ('pending', 'failed');
```

**Propósito**: US-018 (monitorar filas), US-020 (backfill). `failed_jobs` do Laravel só captura falhas terminais — `embedding_jobs` dá visibilidade de todos os estados (incluindo successes com retries). Também habilita "posts pendentes" dashboard para o Operador.

**Alternativa considerada**: reaproveitar só `failed_jobs` do Laravel. Rejeitada porque perde sucessos e torna métricas de throughput (§9 do overview) impossíveis sem instrumentação paralela.

**Índice parcial em `status`**: o volume de linhas `success` domina; queries de Operador são quase sempre "o que está pending/failed". Índice parcial economiza ~95% do espaço do btree.

**Crescimento estimado**: uma linha por job despachado. ~1/post + retries. Com retry exponencial 3x, pior caso 3-4 entries por post. Para 1250 posts do seed, <5000 linhas. Dorme em <1MB.

**Histórias motivadoras**: US-018, US-020.

#### Tabelas que **não** serão criadas (explícito)

- **`recommendation_experiments`** — decisão do usuário: sem A/B e sem feature flags na v1. Pesos vivem em `config/recommender.php`. Se algum dia A/B entrar, migration nova.
- **`follows`** — decisão do usuário: following pool fora da v1.
- **`reports_moderation`** (ou similar) — report é tratado como `interaction_events.type='report'` + hide duro. Sem workflow de moderação.
- **`user_locations`** — locality pool fora da v1.
- **`ranking_metrics_hourly`** (US-031, Should) — adiada. Se queries agregadas em `ranking_logs` ficarem lentas, criar esta tabela de rollup como migration nova. Não vale criar antes de medir.

### 3.4. Índices

| Tabela | Coluna(s) | Tipo | Justificativa |
|---|---|---|---|
| `post_embeddings` | `embedding` | HNSW `vector_cosine_ops` | **Já existe** (não recriar). |
| `users` | `long_term_embedding` | HNSW `vector_cosine_ops` | US-022 (dashboards de "usuários similares") e preparação para clusters. Construção amortizada, custo de insert desprezível em volume do workshop. |
| `users` | `short_term_embedding`, `avoid_embedding` | **Nenhum índice** | Uso esperado é lookup por `user_id`, não ANN entre usuários nesses eixos. HNSW adicionaria overhead sem benefício. |
| `interaction_events` | `(user_id, created_at DESC)` | btree | Principal query: US-025 ("eventos do usuário últimos 180d"). |
| `interaction_events` | `(post_id, type)` | btree | Queries do trending pool (US-027): "quantos likes este post teve em 24h". |
| `interaction_events` | `(type, created_at DESC) WHERE type IN ('like','comment','share')` | btree parcial | Queries agregadas de trending e métricas; parcial evita inflar com dwells ruidosos. |
| `ranking_logs` | `(user_id, post_id, served_at DESC)` | btree | US-017 ("por que servi X para Y?"). |
| `ranking_logs` | `(served_at DESC)` | btree | Jobs de rollup horário (US-031), métricas agregadas. |
| `ranking_logs` | `(source)` | btree | Random baseline (US-030) precisa filtrar rapidamente por `source='random_baseline'`. |
| `user_interest_clusters` | `(user_id, cluster_index)` | UNIQUE btree | Garante no máximo um cluster por índice por usuário. |
| `user_interest_clusters` | `user_id` | btree | Lookup "todos os clusters deste usuário". |
| `embedding_jobs` | `post_id` | btree | "Onde está o job deste post?". |
| `embedding_jobs` | `status WHERE status IN ('pending','failed')` | btree parcial | Economiza espaço; queries só interessadas em não-sucessos. |

### 3.5. Triggers e constraints

- **Triggers**: nenhum. Toda lógica de propagação (atualizar vetores, disparar jobs) é feita em observers/services Laravel — coerente com o estilo do projeto e com as histórias de usuário.
- **Constraints além de FK/UNIQUE**:
  - `interaction_events.type` como `CHECK` enumerando valores. *Decisão pendente*: em Laravel, costuma-se preferir validação na camada de aplicação; neste projeto a regra é "não usar enum", então fica sem check constraint.
  - `interaction_events.weight` como `CHECK (weight BETWEEN -20 AND 20)` — defensa de sanidade, mas não crítica. Deixamos sem.
  - `ranking_logs.position >= 1` — idem.

Posição: **não criamos check constraints em v1**. A camada de aplicação valida; verdade é que o único produtor dessas tabelas será o `InteractionService` / `FeedService`, ambos confiáveis.

---

## 4. Estratégia de migração

### Ordem recomendada

As migrations são independentes entre si no sentido de schema (nenhuma depende estruturalmente de outra), mas a ordem de deploy importa por um detalhe: a lógica de aplicação precisa estar pronta antes da tabela que ela consome. Por isso a ordem abaixo segue a ordem *cronológica* em que o código das user stories será implementado, não a ordem de dependência de schema.

| # | Migration | Motivação principal | Pode rodar antes do deploy de código? |
|---|---|---|---|
| 1 | `add_recommendation_columns_to_users_table` | Colunas de long/short/avoid em `users` | **Sim** — todas nullable, nenhum código existente as lê. |
| 2 | `add_metadata_to_post_embeddings_table` | `embedded_at`, `embedding_model` | **Sim** — com defaults, backfill automático. |
| 3 | `create_interaction_events_table` | Stream de sinais | **Sim** — nenhum insert até os observers novos subirem. |
| 4 | `create_ranking_logs_table` | Logs do ranker | **Sim** — começa a receber inserts no primeiro deploy do `FeedService` novo. |
| 5 | `create_embedding_jobs_table` | Audit de jobs | **Sim** — idem. |
| 6 | `create_user_interest_clusters_table` | Roadmap multi-interest | **Sim** — não há código consumindo ainda. |
| 7 | `create_hnsw_index_on_users_long_term_embedding` | Índice ANN user-user | **Sim** — construção da HNSW é online com `CONCURRENTLY`; seguro mesmo com writes acontecendo. |

### Backfill necessário

- **`post_embeddings.embedded_at` / `embedding_model`**: a migration aplica defaults (`NOW()` e `'gemini-embedding-2-preview:1536'`) para todas as linhas existentes. Nada mais a fazer. Os valores serão imprecisos para linhas antigas (cronologicamente igualam ao momento da migration), mas isso é esperado e documentado.
- **`interaction_events` a partir de `likes` + `comments` existentes**: **opcional** mas **recomendado** para o workshop — hidrata os dados de seed de forma que `RefreshLongTermEmbeddingsJob` e trending pool funcionem desde o primeiro boot. Implementado no seeder `RecommendationDemoSeeder` (§7 item 9).
- **`users.long_term_embedding` inicial**: após subir a migration 1, o `RefreshLongTermEmbeddingsJob` precisa rodar uma vez para popular. Até lá, o `FeedService` cai no cold start (trending). Aceitável.

### Zero-downtime

Todas as migrations desta série são **online-safe** no PostgreSQL:

- `ALTER TABLE ... ADD COLUMN ... NULL` → instantâneo, apenas metadata.
- `ALTER TABLE ... ADD COLUMN ... NOT NULL DEFAULT <literal>` em PG 11+ → instantâneo, apenas metadata (default avaliado lazy). **Cuidado**: defaults do tipo `CURRENT_TIMESTAMP` em PG >= 11 também são metadata-only (validado pela doc oficial).
- `CREATE TABLE` → instantâneo.
- `CREATE INDEX ... USING hnsw` → bloqueia writes na tabela durante build. **Solução**: usar `CREATE INDEX CONCURRENTLY`. A migration usa `DB::statement` com `CONCURRENTLY`. Nota: `CONCURRENTLY` não pode rodar dentro de uma transaction — a migration explicitamente desativa transaction com `public $withinTransaction = false;`.
- Índices btree em tabelas vazias são instantâneos.

### Dívida técnica tratada à parte

O FK `post_embeddings.post_id` hoje não tem `cascade on delete`. Quando um post é deletado (US-3.5), isso quebra. **Fora do escopo desta tarefa** — documentamos e criamos migration específica se virar problema. Sugestão: `ALTER TABLE post_embeddings DROP CONSTRAINT post_embeddings_post_id_foreign; ALTER TABLE post_embeddings ADD CONSTRAINT ... FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE;` — ambos instantâneos.

---

## 5. Estimativas de impacto

### Storage (estimativa com base no seed: 300 usuários, 1250 posts, ~likes/comments proporcionais)

| Item | Linhas | Bytes/linha (~) | Total |
|---|---:|---:|---:|
| `users.*_embedding` (3 × vector(1536)) | 300 | ~18 KB | ~5.4 MB |
| `post_embeddings.embedded_at` + `embedding_model` | 1250 | ~60 bytes | ~75 KB |
| `interaction_events` (ano 1, workshop) | ~500k | 150 bytes | ~75 MB |
| `ranking_logs` (ano 1, workshop) | ~20M | 90 bytes | ~1.8 GB |
| `user_interest_clusters` | 300 × 5 | 6 KB | ~9 MB |
| `embedding_jobs` | ~1500 | 120 bytes | <1 MB |
| HNSW em `users.long_term_embedding` | 300 | ~4.5 KB/vec | ~1.4 MB |

**Total aproximado**: ~2 GB no primeiro ano, **dominado por `ranking_logs`**. Para a escala do workshop (300 usuários, uso demo), ficará muito menor. Rollup + purga só viram obrigatórios em produção real.

### Tempo esperado das migrations

- Migrations 1-6: instantâneas (<1s cada) — só DDL.
- Migration 7 (HNSW user-user): ~5s para 300 users; O(n·log n) — <1min para 100k users.
- Backfill dos 1250 posts no `embedded_at` (via default): instantâneo, é só metadata.

### Locks

- `ALTER TABLE users ADD COLUMN` exige **AccessExclusiveLock**, mas a fase bloqueante dura microssegundos em PG 11+ (só atualiza catálogo). Okay em produção.
- `CREATE INDEX CONCURRENTLY` não pega ExclusiveLock na tabela. Usado na migration 7.

---

## 6. Rollback plan

Cada migration implementa `down()` reversível. Notas específicas:

| Migration | Comportamento de `down()` | Risco de perda de dados |
|---|---|---|
| 1. `add_recommendation_columns_to_users_table` | Dropa as 5 colunas novas. | Perda dos vetores de usuário recém-calculados. Recalculáveis via `RefreshLongTermEmbeddingsJob` (mas é processo longo e custa Gemini). **Recomendar snapshot antes.** |
| 2. `add_metadata_to_post_embeddings_table` | Dropa `embedded_at` e `embedding_model`. | Perda de rastreabilidade temporal. Sem impacto funcional. |
| 3. `create_interaction_events_table` | Drop table. | **Perda total dos sinais históricos** — não recalculáveis. Antes de dropar, exportar CSV. |
| 4. `create_ranking_logs_table` | Drop table. | Perda de logs de ranking. Não crítico (só debug). |
| 5. `create_embedding_jobs_table` | Drop table. | Perda de audit trail. Não crítico. |
| 6. `create_user_interest_clusters_table` | Drop table. | Recalculável via `RefreshInterestClustersJob` (quando existir). |
| 7. `create_hnsw_index_on_users_long_term_embedding` | `DROP INDEX CONCURRENTLY`. | Nenhum (é só índice). |

### Estratégia geral de rollback

1. **Rollback por migration individual** (`php artisan migrate:rollback --step=1`) é seguro para as migrations 2, 4, 5, 6 e 7.
2. **Rollback que envolva 1 ou 3**: executar em janela programada com backup prévio. Idealmente, ambas rollam juntas se o motivo for "desfazer o novo sistema inteiro".
3. **Não há rollback de dados** — o backfill de `embedded_at` default perde a precisão temporal. Aceito.

---

## 7. Migrations geradas

Todos os arquivos seguem os padrões do projeto: `return new class extends Migration`, `Schema::` onde possível, `DB::statement` para DDL específico do pgvector, comentários de cabeçalho referenciando a US principal. Timestamp sequencial `2026_04_23_HHMMSS` para ficar estritamente depois das existentes.

| # | Arquivo | US principais |
|---|---|---|
| 1 | `database/migrations/2026_04_23_090001_add_recommendation_columns_to_users_table.php` | US-024, US-025, US-026 |
| 2 | `database/migrations/2026_04_23_090002_add_metadata_to_post_embeddings_table.php` | US-020, US-023 |
| 3 | `database/migrations/2026_04_23_090003_create_interaction_events_table.php` | US-002..006, US-010, US-011 |
| 4 | `database/migrations/2026_04_23_090004_create_ranking_logs_table.php` | US-017, US-028, US-030 |
| 5 | `database/migrations/2026_04_23_090005_create_embedding_jobs_table.php` | US-018, US-020 |
| 6 | `database/migrations/2026_04_23_090006_create_user_interest_clusters_table.php` | Roadmap (overview §4) |
| 7 | `database/migrations/2026_04_23_090007_create_hnsw_index_on_users_long_term_embedding.php` | US-022 (preparação multi-interest) |

Seeder demo adicional criado:

- `database/seeders/RecommendationDemoSeeder.php` — hidrata `interaction_events` a partir de `likes` e `comments` existentes, gera dwells sintéticos por usuário e pré-popula `users.long_term_updated_at`. Invocável via `./vendor/bin/sail artisan db:seed --class=RecommendationDemoSeeder`.

---

## 8. Perguntas em aberto (herdadas + novas)

Consolidadas dos docs anteriores + desta rodada:

1. **Retenção de `interaction_events`**: particionar por mês? Dropar dwells >180d? (§3.3, §11 item 10 do overview)
2. **Retenção de `ranking_logs`**: particionar + purgar >90d? (§3.3, §5)
3. **Corrigir FK `post_embeddings.post_id` para cascade**: quando? (§1.3, §4)
4. **Remover `users.embedding` legado**: quando cutover do novo pipeline estabilizar? (§1.1)
5. **Check constraint em `interaction_events.type`**: adotar enum-like CHECK ou validar só em código? (§3.5)
6. **`ranking_metrics_hourly`**: criar agora ou esperar até dashboards (US-022) ficarem lentos? (§3.3)
7. **Política de índice em `users.short_term_embedding`**: se no futuro quisermos "usuários similares por mood atual", HNSW adicional precisa ser criado. Não agora.
