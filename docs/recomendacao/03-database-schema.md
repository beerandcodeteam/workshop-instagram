# Sistema de Recomendação — Database Schema (DBML)

## 1. Contexto

Este documento descreve, em DBML, o **schema alvo** para dar suporte ao sistema de recomendação definido em `01-overview.md` e nas histórias de `02-user-stories.md`. Estende o schema base documentado em `docs/database-schema.md`.

A convenção segue Laravel 13: PK `bigIncrements`, `timestamps()` nas tabelas principais, snake_case pluralizado, FKs `{singular}_id`, soft delete apenas onde há história explícita exigindo undelete.

## 2. Decisões de design guiadas pelas guidelines

- **Sem colunas enum/string enum**. Todos os campos categóricos viram lookup table:
    - `interaction_types` — `like`, `unlike`, `comment`, `share`, `view`, `skip_fast`, `hide`, `report`, `author_block`.
    - `embedding_models` — versão/fornecedor do modelo que gerou cada vetor (hoje `gemini-embedding-2-preview` 1536d; amanhã pode mudar).
    - `recommendation_sources` — `ann_long_term`, `ann_short_term`, `ann_cluster`, `trending`, `following`, `locality`, `explore`, `control`.
    - `media_types` — `image`, `video`, `audio` (categoria MIME do arquivo em `post_media`, complementar a `post_types` que é o tipo do post inteiro).
- **Embedding inline nas tabelas principais** (não em tabela satélite). `posts.embedding`, `users.long_term_embedding`, etc. — cada coluna com seu par `{name}_updated_at` e `{name}_model_id`. Isso desaparece a tabela `post_embeddings` atual (ver §6 para estratégia de coexistência).
- **Dimensão 1536** em todos os embeddings → cabe em `vector(1536)` e em índice HNSW (limite de `vector` é 2000 dims; `halfvec` só seria necessário acima disso). Mantemos `vector`, não `halfvec`.
- **`post_interactions` como única tabela append-only** cobrindo todos os sinais (US-026). `likes` e `comments` permanecem por serem entidades de domínio (UI precisa ler "quem curtiu", "lista de comentários"); `post_interactions` é o log imutável que alimenta agregações de vetor.
- **Cascade on delete** para posses fortes (post_media, likes, comments, post_interactions de um post apagado). **Set null** para FKs de lookup/observabilidade — ex.: se um `recommendation_sources` for removido, os `recommendation_logs` ficam com `NULL` em vez de morrer.
- **Soft delete** apenas em `posts` (US-3.5 permite delete, mas análise offline precisa do histórico por algumas semanas) — **decisão pendente §11.11** abaixo.
- **Índices HNSW cosseno** (`vector_cosine_ops`) em todos os vetores que participam de ANN. `users.short_term_embedding` também recebe HNSW porque o ANN pode ser feito no vetor (improvável no MVP, mas mantém a simetria de custo baixo).

## 3. Extensão PostgreSQL obrigatória

```sql
CREATE EXTENSION IF NOT EXISTS vector;
```

Já habilitada hoje via `Schema::ensureVectorExtensionExists()`. Este documento assume extensão presente.

## 4. Resumo de entidades

| Tabela                         | Categoria      | Propósito                                                                 |
|--------------------------------|----------------|---------------------------------------------------------------------------|
| `users`                        | core (alter.)  | Recebe 3 embeddings (long/short/avoid) + metadados                        |
| `posts`                        | core (alter.)  | Recebe `embedding` inline + metadados; absorve `post_embeddings`          |
| `post_media`                   | core (alter.)  | Passa a usar `media_type_id` (FK) em vez de inferir pelo MIME             |
| `likes`                        | core           | Inalterado                                                                |
| `comments`                     | core           | Inalterado                                                                |
| `post_types`                   | lookup         | Inalterado (`text`/`image`/`video`)                                       |
| `interaction_types`            | lookup (nova)  | Catalog dos kinds de sinal                                                |
| `embedding_models`             | lookup (nova)  | Modelo/versão que gerou um vetor                                          |
| `recommendation_sources`       | lookup (nova)  | De qual fonte veio o candidato (ann_lt, trending, etc.)                   |
| `media_types`                  | lookup (nova)  | Categoria do arquivo em `post_media`                                      |
| `post_interactions`            | evento (nova)  | Append-only de todos os sinais                                            |
| `user_interest_clusters`       | agregado (nova)| 3–7 centroides k-means por usuário (multi-interesse)                      |
| `reports`                      | moderação (nova)| Motivo estruturado do report (complementa `post_interactions.kind=report`) |
| `recommendation_logs`          | observab. (nova)| Rastro de ranking por `(user, post, request)` — "por que recomendei"     |
| `recommendation_experiments`   | observab. (nova)| Atribuição A/B de usuário a variante                                    |

Totais: 2 colunas novas em `users` (×3 vetores = 9 colunas), 3 colunas novas em `posts`, 1 coluna nova em `post_media`, 9 tabelas novas.

## 5. Schema (DBML)

```dbml
// Workshop Instagram — Recommendation layer schema
// DBML reference: https://dbml.dbdiagram.io/docs
//
// Extensão PostgreSQL obrigatória: vector (pgvector)
//   CREATE EXTENSION IF NOT EXISTS vector;
//
// Este arquivo estende docs/database-schema.md (schema base do clone).
// Tabelas `users`, `posts`, `post_media` aparecem aqui apenas com as
// alterações propostas; as colunas pré-existentes seguem conforme o
// schema base e não são repetidas por brevidade.

Project workshop_instagram_recommendation {
  database_type: 'PostgreSQL'
  Note: '''
  Extensão do schema base adicionando a camada de recomendação:
  embeddings multimodais (Gemini 1536d) em posts e usuários (long/short/avoid),
  sinais de interação ricos em tabela append-only, clusters de interesse,
  e observabilidade de ranking (traces + A/B).
  '''
}

// ============================================================
// Lookup tables (novas)
// ============================================================

Table interaction_types {
  id bigint [pk, increment]
  name varchar(50) [not null, unique, note: 'Human label: "Curtida", "Comentário", etc.']
  slug varchar(50) [not null, unique, note: 'Machine key: like, unlike, comment, share, view, skip_fast, hide, report, author_block']
  default_weight decimal(6,3) [not null, note: 'Peso base do sinal (ex.: like=+1.0, report=-3.0). Calibrável em runtime via config, mas este é o default inicial.']
  half_life_hours integer [not null, note: 'Half-life do decay em horas (ex.: like=720 = 30d, view=6 = 6h).']
  is_positive boolean [not null, note: 'true para sinais de positivos (entram em LT/ST), false para negativos (entram em AVOID).']
  is_active boolean [not null, default: true]
  created_at timestamp [null]
  updated_at timestamp [null]

  Note: 'Seeded via InteractionTypeSeeder. Pesos/half_lives são calibráveis via config/recommendation.php em runtime — os valores aqui são o "contrato inicial".'
}

Table embedding_models {
  id bigint [pk, increment]
  name varchar(100) [not null, unique, note: 'Ex.: "Gemini Embedding 2 Preview"']
  slug varchar(100) [not null, unique, note: 'Ex.: "gemini-embedding-2-preview"']
  provider varchar(50) [not null, note: 'google, openai, cohere, ...']
  dimensions integer [not null, note: 'Tamanho do vetor produzido (1536 para o modelo atual).']
  is_active boolean [not null, default: true, note: 'Modelos antigos ficam inativos mas não são apagados — embeddings históricos ainda referenciam.']
  deprecated_at timestamp [null, note: 'Marcado quando o modelo sai de uso — embeddings gerados por ele devem ser refeitos (US-022).']
  created_at timestamp [null]
  updated_at timestamp [null]

  Note: 'Referenciado por todos os embeddings (posts.embedding_model_id, users.long_term_embedding_model_id, etc.) para rastrear qual modelo gerou qual vetor. Essencial para backfill quando o modelo muda.'
}

Table recommendation_sources {
  id bigint [pk, increment]
  name varchar(50) [not null, unique]
  slug varchar(50) [not null, unique, note: 'ann_long_term, ann_short_term, ann_cluster, trending, following, locality, explore, control']
  is_active boolean [not null, default: true]
  created_at timestamp [null]
  updated_at timestamp [null]

  Note: 'Cada candidato em um feed vem de uma fonte. recommendation_logs referencia esta tabela para rastrear qual fonte produziu qual recomendação (US-016, US-018, US-024).'
}

Table media_types {
  id bigint [pk, increment]
  name varchar(50) [not null, unique]
  slug varchar(50) [not null, unique, note: 'image, video, audio']
  mime_prefix varchar(30) [not null, note: 'Ex.: "image/", "video/", "audio/" — usado para validação no upload.']
  is_active boolean [not null, default: true]
  created_at timestamp [null]
  updated_at timestamp [null]

  Note: 'Categoria MIME de cada arquivo em post_media. Distinto de post_types (tipo do post inteiro — texto/imagem/vídeo).'
}

// ============================================================
// Core tables — alterações
// ============================================================

Table users [headercolor: #8134AF] {
  // ---- colunas do schema base (resumidas) ----
  id bigint [pk, increment]
  name varchar(255) [not null]
  email varchar(255) [not null, unique]
  email_verified_at timestamp [null]
  password varchar(255) [not null]
  remember_token varchar(100) [null]
  created_at timestamp [null]
  updated_at timestamp [null]

  // ---- novas colunas da camada de recomendação ----
  long_term_embedding "vector(1536)" [null, note: '''
    Representa o gosto estável do usuário (90–180d, half-life 30d).
    NULL enquanto Σ pesos < threshold (ver overview §6).
    Recomputado em batch diário via RefreshLongTermEmbeddingsJob (US-027).
  ''']
  long_term_embedding_updated_at timestamp [null, note: 'Quando o LT foi recalculado pela última vez.']
  long_term_embedding_model_id bigint [null, ref: > embedding_models.id, note: 'ON DELETE SET NULL.']

  short_term_embedding "vector(1536)" [null, note: '''
    Gosto da sessão atual + últimas 24–48h, half-life 6h.
    Atualizado em tempo real via RefreshShortTermEmbeddingJob (US-028),
    com cache em Redis (rec:user:{id}:short_term, TTL 1h).
  ''']
  short_term_embedding_updated_at timestamp [null]
  short_term_embedding_model_id bigint [null, ref: > embedding_models.id]

  avoid_embedding "vector(1536)" [null, note: '''
    Agregação de hide/report/skip_fast (US-003, US-004).
    Usado como PENALIDADE no ranking (score -= β · cos(p, avoid)),
    NUNCA subtraído do LT/ST.
  ''']
  avoid_embedding_updated_at timestamp [null]
  avoid_embedding_model_id bigint [null, ref: > embedding_models.id]

  Indexes {
    long_term_embedding [name: 'users_lt_embedding_hnsw_idx', note: 'USING hnsw (long_term_embedding vector_cosine_ops) WHERE long_term_embedding IS NOT NULL']
    short_term_embedding [name: 'users_st_embedding_hnsw_idx', note: 'USING hnsw (short_term_embedding vector_cosine_ops) WHERE short_term_embedding IS NOT NULL']
    avoid_embedding [name: 'users_avoid_embedding_hnsw_idx', note: 'USING hnsw (avoid_embedding vector_cosine_ops) WHERE avoid_embedding IS NOT NULL']
  }

  Note: '''
  A coluna `embedding` existente no schema atual (single centroid via
  CalculateUserCentroidJob) é renomeada para `long_term_embedding` e
  passa a ser recalculada com pesos/decay apropriados. Ver §6 para
  estratégia de migração.
  '''
}

Table posts [headercolor: #DD2A7B] {
  // ---- colunas do schema base (resumidas) ----
  id bigint [pk, increment]
  user_id bigint [not null, ref: > users.id]
  post_type_id bigint [not null, ref: > post_types.id]
  body text [null]
  created_at timestamp [null]
  updated_at timestamp [null]

  // ---- novas colunas da camada de recomendação ----
  embedding "vector(1536)" [null, note: '''
    Embedding multimodal (body + mídias) gerado via Gemini.
    NULL até GeneratePostEmbeddingJob concluir (US-011).
    Posts com NULL são filtrados no candidate generation.
  ''']
  embedding_updated_at timestamp [null, note: 'Atualizado quando body muda (US-014) ou em backfill (US-022).']
  embedding_model_id bigint [null, ref: > embedding_models.id]

  reports_count integer [not null, default: 0, note: '''
    Denormalização de COUNT(*) FROM reports WHERE post_id = posts.id.
    Mantido por trigger (ou por observer, ver §6). Usado como filtro
    duro em Stage 1 quando >= threshold (US-015).
  ''']

  deleted_at timestamp [null, note: '''
    Soft delete. Permite análise offline por ~7d após delete (ranking_traces
    aponta para o post); após retenção, hard delete via job de purge (US-034).
    [DECISÃO PENDENTE §11.11]: confirmar se soft delete atende ou se o cascade
    duro atual em US-3.5 deve ser preservado.
  ''']

  Indexes {
    embedding [name: 'posts_embedding_hnsw_idx', note: 'USING hnsw (embedding vector_cosine_ops) WHERE embedding IS NOT NULL']
    (created_at) [name: 'posts_created_at_idx']
    (user_id) [name: 'posts_user_id_idx']
    (post_type_id) [name: 'posts_post_type_id_idx']
    (reports_count) [name: 'posts_reports_count_idx', note: 'Filtro rápido no candidate generation.']
  }

  Note: '''
  A tabela `post_embeddings` existente é dissolvida: a coluna `embedding`
  passa a viver diretamente em `posts`. Decisão em §6.
  '''
}

Table post_media {
  id bigint [pk, increment]
  post_id bigint [not null, ref: > posts.id, note: 'ON DELETE CASCADE']
  media_type_id bigint [not null, ref: > media_types.id, note: 'ON DELETE RESTRICT — preservar categoria mesmo se lookup for alterada.']
  file_path varchar(2048) [not null]
  sort_order integer [not null, default: 0]
  created_at timestamp [null]
  updated_at timestamp [null]

  Indexes {
    (post_id, sort_order) [unique, name: 'post_media_post_id_sort_order_unique']
  }

  Note: '''
  `media_type_id` substitui a inferência via MIME que GeneratePostEmbeddingJob
  faz hoje com finfo (ver PostObserver/GeneratePostEmbeddingJob).
  Backfill: infere a partir do mimetype atual dos arquivos no MinIO.
  '''
}

// ============================================================
// Interaction log (nova — núcleo dos sinais)
// ============================================================

Table post_interactions {
  id bigint [pk, increment]
  user_id bigint [not null, ref: > users.id, note: 'ON DELETE CASCADE']
  post_id bigint [not null, ref: > posts.id, note: 'ON DELETE CASCADE']
  interaction_type_id bigint [not null, ref: > interaction_types.id, note: 'ON DELETE RESTRICT']
  weight decimal(6,3) [not null, note: '''
    Peso efetivo deste evento. Por padrão = interaction_type.default_weight,
    mas pode ser override — ex.: view ganha weight proporcional ao dwell_ms,
    unlike pode carregar weight parcial.
  ''']
  session_id varchar(64) [null, note: 'ID da sessão do usuário (gerado no login / sticky no cookie). Permite agregar "o que aconteceu nesta sessão".']
  duration_ms integer [null, note: 'Apenas para view/dwell events: milissegundos que o post ficou ≥50% visível. NULL em like/comment/share/etc.']
  context jsonb [null, note: '''
    Contexto opcional registrado junto do evento:
    { "device": "mobile|desktop",
      "hour_of_day": 14,
      "day_of_week": 1,
      "locality": "BR-SP",
      "feed_source": "ann_short_term",
      "feed_position": 3,
      "experiment_variant": "B" }
    Usado para boosts contextuais e para US-016/US-018/US-021.
  ''']
  created_at timestamp [not null]

  Indexes {
    (user_id, created_at) [name: 'post_interactions_user_created_idx', note: 'DESC — agregação LT/ST por usuário.']
    (post_id, created_at) [name: 'post_interactions_post_created_idx', note: 'DESC — popularidade/trending por post.']
    (interaction_type_id, created_at) [name: 'post_interactions_type_created_idx', note: 'DESC — análises por tipo de sinal.']
    (user_id, post_id, interaction_type_id) [name: 'post_interactions_user_post_type_idx', note: 'Lookups pontuais (ex.: "esse usuário já escondeu esse post?").']
    (session_id) [name: 'post_interactions_session_idx']
  }

  Note: '''
  Tabela APPEND-ONLY. Ausência de updated_at é intencional — eventos são
  imutáveis. Purge anual via US-034, não UPDATE/DELETE no hot-path.

  Dual-write: LikeObserver e (futuro) CommentObserver gravam em likes/comments
  E em post_interactions durante a transição (US-026). Eventualmente likes
  /comments podem se tornar views materializadas sobre esta tabela, mas não
  no MVP.

  Volume esperado (seed 300 users × uso moderado): ~10k rows/semana.
  Particionar por mês se passar de 10M rows.
  '''
}

Table reports {
  id bigint [pk, increment]
  user_id bigint [not null, ref: > users.id, note: 'ON DELETE CASCADE']
  post_id bigint [not null, ref: > posts.id, note: 'ON DELETE CASCADE']
  reason varchar(100) [not null, note: '''
    Motivo escolhido pelo reporter (spam, hate_speech, nudity, harassment, ...).
    [DECISÃO PENDENTE]: transformar em lookup `report_reasons`? Por ora varchar
    simples — se a UI crescer, refatora.
  ''']
  details text [null, note: 'Texto livre opcional.']
  resolved_at timestamp [null, note: 'Marcado quando operador revisa.']
  resolved_by_user_id bigint [null, ref: > users.id, note: 'Operador que resolveu. ON DELETE SET NULL.']
  created_at timestamp [null]
  updated_at timestamp [null]

  Indexes {
    (post_id) [name: 'reports_post_id_idx']
    (user_id, post_id) [unique, name: 'reports_user_post_unique', note: 'Um usuário reporta um post só uma vez.']
    (resolved_at) [name: 'reports_resolved_at_idx']
  }

  Note: '''
  Complementa post_interactions (kind=report): este é o registro estruturado
  com motivo e status de moderação. post_interactions.kind=report é gravado
  em paralelo para alimentar o vetor AVOID (US-004).
  '''
}

// ============================================================
// Interesse multi-vetor (nível avançado)
// ============================================================

Table user_interest_clusters {
  id bigint [pk, increment]
  user_id bigint [not null, ref: > users.id, note: 'ON DELETE CASCADE']
  cluster_index smallint [not null, note: 'Índice 0..k-1 do cluster. k é escolhido por silhouette score, 3..7.']
  embedding "vector(1536)" [not null, note: 'Centroide do cluster (média normalizada dos posts que caíram nele).']
  weight decimal(6,4) [not null, note: 'Fração de interações positivas que pertencem a este cluster (soma = 1.0 por user).']
  sample_count integer [not null, note: 'Quantos posts geraram este centroide.']
  embedding_model_id bigint [not null, ref: > embedding_models.id]
  computed_at timestamp [not null, note: 'Quando o k-means foi rodado por último.']
  created_at timestamp [null]
  updated_at timestamp [null]

  Indexes {
    (user_id) [name: 'user_interest_clusters_user_idx']
    (user_id, cluster_index) [unique, name: 'user_interest_clusters_user_cluster_unique']
    embedding [name: 'user_interest_clusters_embedding_hnsw_idx', note: 'USING hnsw (embedding vector_cosine_ops) — usado pelo CandidateGenerator::annByClusters quando invertemos a busca (posts próximos de um cluster).']
  }

  Note: '''
  Recalculado por RefreshInterestClustersJob (US-033) semanalmente ou quando
  delta de interações > 20. Rows antigas são deletadas e substituídas por um
  novo conjunto — não há UPDATE in-place por cluster.

  Usuários com < 30 interações positivas NÃO têm rows aqui.
  '''
}

// ============================================================
// Observabilidade (novas)
// ============================================================

Table recommendation_logs {
  id bigint [pk, increment]
  request_id uuid [not null, note: '''
    Agrupa todos os posts retornados em uma mesma requisição de feed.
    Permite reconstruir "o feed inteiro do usuário X às 14h23".
  ''']
  user_id bigint [not null, ref: > users.id, note: 'ON DELETE CASCADE']
  post_id bigint [not null, ref: > posts.id, note: 'ON DELETE CASCADE']
  recommendation_source_id bigint [null, ref: > recommendation_sources.id, note: 'ON DELETE SET NULL — qual fonte do candidate gen trouxe o post.']
  score decimal(10,6) [not null, note: 'Score final composto que decidiu a posição.']
  rank_position integer [not null, note: '0-based, ordem em que o post apareceu no feed.']
  scores_breakdown jsonb [null, note: '''
    { "sim_lt": 0.82, "sim_st": 0.71, "sim_avoid": 0.10,
      "recency_boost": 0.15, "trending_boost": 0.00,
      "context_boost": 0.02, "mmr_penalty": -0.05,
      "final_score": 0.83 }
  ''']
  filtered_reason varchar(100) [null, note: '''
    NULL se o post apareceu no feed. Preenchido se foi filtrado:
    "already_seen", "author_blocked", "reports_threshold", "quota_exceeded",
    "mmr_dropped", "cold_start_exclusion", etc. Permite responder
    "por que NÃO recomendei" (US-016).
  ''']
  experiment_variant varchar(50) [null, note: 'Variante A/B ativa nesta request (US-021). NULL = sem experimento.']
  created_at timestamp [not null]

  Indexes {
    (user_id, post_id, created_at) [name: 'recommendation_logs_user_post_created_idx', note: 'Lookup direto para US-016 ("por que vi esse post?").']
    (request_id) [name: 'recommendation_logs_request_idx']
    (created_at) [name: 'recommendation_logs_created_idx', note: 'Purge após 7 dias (US-017).']
    (recommendation_source_id, created_at) [name: 'recommendation_logs_source_created_idx', note: 'Métricas de fonte no dashboard (US-018).']
  }

  Note: '''
  Append-only. Purgado após 7 dias por PurgeRecommendationLogsJob (US-017).
  Escrito assíncronamente pela fila `traces` para não bloquear o feed.

  Volume esperado: ~10 rows por request de feed × ~1000 requests/dia (seed)
  = ~70k rows em 7 dias. Particionamento por dia se escalar além de 10M.
  '''
}

Table recommendation_experiments {
  id bigint [pk, increment]
  user_id bigint [not null, ref: > users.id, note: 'ON DELETE CASCADE']
  experiment_name varchar(100) [not null, note: 'Ex.: "ranking_formula_v2", "mmr_lambda_sweep".']
  variant varchar(50) [not null, note: 'Ex.: "A", "B", "control".']
  assigned_at timestamp [not null]
  expired_at timestamp [null, note: 'Quando a atribuição deixa de valer (fim do experimento ou rotação).']
  created_at timestamp [null]
  updated_at timestamp [null]

  Indexes {
    (user_id, experiment_name) [unique, name: 'recommendation_experiments_user_experiment_unique', note: 'Um usuário tem no máximo uma variante ativa por experimento.']
    (experiment_name, variant) [name: 'recommendation_experiments_exp_variant_idx', note: 'Agregação de métricas por variante (US-018/US-021).']
  }

  Note: '''
  Persistência opcional: a atribuição pode ser calculada on-the-fly via
  hash(user_id + experiment_name) sem gravar. Gravamos aqui quando:
  (a) queremos rotação diária (US-024 random serving),
  (b) queremos garantir estabilidade de variante entre sessões,
  (c) queremos auditoria histórica.
  '''
}

// ============================================================
// Relationships (resumo)
// ============================================================

// Core
Ref: posts.user_id > users.id
Ref: posts.post_type_id > post_types.id
Ref: post_media.post_id > posts.id           [delete: cascade]
Ref: post_media.media_type_id > media_types.id

// Embedding models
Ref: posts.embedding_model_id > embedding_models.id
Ref: users.long_term_embedding_model_id > embedding_models.id
Ref: users.short_term_embedding_model_id > embedding_models.id
Ref: users.avoid_embedding_model_id > embedding_models.id
Ref: user_interest_clusters.embedding_model_id > embedding_models.id

// Interaction log
Ref: post_interactions.user_id > users.id    [delete: cascade]
Ref: post_interactions.post_id > posts.id    [delete: cascade]
Ref: post_interactions.interaction_type_id > interaction_types.id

// Moderation
Ref: reports.user_id > users.id              [delete: cascade]
Ref: reports.post_id > posts.id              [delete: cascade]
Ref: reports.resolved_by_user_id > users.id  [delete: set null]

// Clusters
Ref: user_interest_clusters.user_id > users.id [delete: cascade]

// Observability
Ref: recommendation_logs.user_id > users.id  [delete: cascade]
Ref: recommendation_logs.post_id > posts.id  [delete: cascade]
Ref: recommendation_logs.recommendation_source_id > recommendation_sources.id [delete: set null]
Ref: recommendation_experiments.user_id > users.id [delete: cascade]
```

## 6. Estratégia de coexistência com o schema atual

O schema atual tem duas estruturas que **mudam semanticamente** neste design:

### 6.1. Dissolução de `post_embeddings` em `posts.embedding`

Hoje existe `post_embeddings (id, post_id FK cascade, embedding vector(1536), timestamps)` com `HNSW (vector_cosine_ops)`, 1:1 com `posts` na prática (o job cria uma row por post, cascade apaga junto).

Proposta:
- Adicionar `posts.embedding`, `posts.embedding_updated_at`, `posts.embedding_model_id`.
- Backfill: `UPDATE posts SET embedding = pe.embedding, embedding_updated_at = pe.created_at, embedding_model_id = <gemini-2-preview> FROM post_embeddings pe WHERE pe.post_id = posts.id`.
- Criar índice HNSW novo em `posts.embedding`.
- Atualizar `GeneratePostEmbeddingJob` para escrever em `posts.embedding` diretamente.
- Atualizar `App\Livewire\Pages\Feed\Index::render()` para `JOIN posts` em vez de `JOIN post_embeddings`.
- Validar em staging por N dias com dual-write (escreve nos dois).
- Depois: `DROP TABLE post_embeddings`.

Justificativa: 1:1 forçado não ganha nada com tabela satélite. Simplifica query de ranking (um `JOIN` a menos). Mantém compatibilidade porque o índice HNSW é equivalente.

**Decisão pendente de overview §11.8 resolvida aqui por default**: substituir em vez de manter histórico. Se alguém quiser histórico, usa `embedding_model_id` + `embedding_updated_at` como proxy de versão.

### 6.2. Repurpose de `users.embedding` → `users.long_term_embedding`

Hoje `users.embedding` é o centroide simples calculado por `CalculateUserCentroidJob` a partir de likes.

Proposta:
- Rename `users.embedding` → `users.long_term_embedding`.
- Adicionar `users.long_term_embedding_updated_at`, `users.long_term_embedding_model_id`.
- Adicionar `users.short_term_embedding` + pares de metadados.
- Adicionar `users.avoid_embedding` + pares de metadados.
- Backfill: `long_term_embedding_updated_at = NOW()`, `long_term_embedding_model_id = <gemini-2-preview>`.
- `CalculateUserCentroidJob` é deprecado em favor de `RefreshLongTermEmbeddingsJob` (US-027) e `RefreshShortTermEmbeddingJob` (US-028); `LikeObserver` passa a disparar `RefreshShortTermEmbeddingJob`.

Justificativa: semanticamente o `embedding` atual **é** um long-term aproximado, só que mal ponderado. Renomear torna o papel explícito e libera espaço para os outros dois vetores.

### 6.3. `media_types` em `post_media`

Proposta:
- Criar lookup `media_types` (seed: image/video/audio).
- Adicionar `post_media.media_type_id` nullable.
- Backfill: infere pelo MIME atual do arquivo em MinIO (ou pela extensão do `file_path`).
- Tornar NOT NULL depois do backfill.

Justificativa: hoje `GeneratePostEmbeddingJob` usa `finfo->buffer($bytes)` para descobrir o MIME — ineficiente (re-baixa o arquivo) e frágil. Um FK para lookup resolve de vez.

### 6.4. Lookup `interaction_types` + dual-write para `likes`

Proposta:
- Criar `interaction_types` com os 9 kinds.
- Criar `post_interactions`.
- `LikeObserver::created` passa a gravar em `likes` **e** em `post_interactions`. Idem para `Comment` (novo observer).
- `post_interactions` vira fonte de verdade para agregação de vetor; `likes` / `comments` permanecem como fonte para UI (count, lista).
- Eventualmente (fora do MVP) `likes` / `comments` podem virar views materializadas sobre `post_interactions`. Não neste ciclo.

## 7. Rastreabilidade para as histórias de usuário

| Tabela / coluna nova                                   | Histórias cobertas                                     |
|--------------------------------------------------------|--------------------------------------------------------|
| `posts.embedding` + HNSW                               | US-001, US-011, US-014, US-022                         |
| `posts.reports_count`                                  | US-004, US-015                                         |
| `posts.deleted_at` (soft delete)                       | US-016, US-017 (rastros apontam para posts deletados)  |
| `users.long_term_embedding` + metadados                | US-001, US-005, US-027                                 |
| `users.short_term_embedding` + metadados               | US-002, US-010, US-028                                 |
| `users.avoid_embedding` + metadados                    | US-003, US-004, US-029                                 |
| `post_interactions`                                    | US-002, US-003, US-004, US-008, US-010, US-026        |
| `interaction_types` (lookup)                           | US-020, US-026                                         |
| `embedding_models` (lookup)                            | US-022 (backfill quando modelo muda)                   |
| `media_types` (lookup)                                 | US-011 (eficiência no job de embedding)                |
| `user_interest_clusters`                               | US-006, US-033                                         |
| `reports`                                              | US-004, US-015                                         |
| `recommendation_logs`                                  | US-009, US-016, US-017, US-018, US-024                 |
| `recommendation_sources` (lookup)                      | US-007, US-009, US-016, US-018                         |
| `recommendation_experiments`                           | US-021, US-024                                         |
| Índices HNSW nos 4 vetores de `users`                  | US-001, US-002 (baixa latência do ANN)                 |
| Índices em `post_interactions (user, created_at)`      | US-027, US-028                                         |
| Índices em `post_interactions (post, created_at)`      | US-030                                                 |

## 8. Fora do escopo neste documento

- **Migrations Laravel reais** (arquivos em `database/migrations/`) — o escopo do task atual é apenas a modelagem DBML. Geração de migrations fica como entregável separado.
- **Seeders** (beyond lookups) — `InteractionTypeSeeder`, `EmbeddingModelSeeder`, `RecommendationSourceSeeder`, `MediaTypeSeeder` são triviais e podem seguir o padrão de `PostTypeSeeder` existente.
- **Cache Redis schema** (keys, TTLs) — documentado em `01-overview.md` §5.5/§5.6; não é schema relacional.
- **Particionamento de tabelas grandes** — `post_interactions`, `recommendation_logs` podem precisar de partitioning por mês/dia quando volume crescer; fora do MVP.
- **Retenção LGPD / direito ao esquecimento** — política completa de anonimização/delete fica fora do workshop (overview §8 já destaca).

## Perguntas em aberto

Lista consolidada de decisões pendentes levantadas por este documento:

1. **§5 (reports.reason)**: transformar em FK para `report_reasons` (lookup) ou manter varchar? Impacto baixo, decisão depende do tamanho esperado do vocabulário de motivos na UI.
2. **§5 (posts.deleted_at)**: confirmar se soft delete substitui o comportamento atual de hard delete + cascade de US-3.5. Alternativa: manter hard delete e deixar `recommendation_logs`/`post_interactions` órfãos por 7 dias até o purge natural (com `ON DELETE CASCADE`).
3. **§5 (posts.reports_count)**: mantido por trigger SQL ou por `ReportObserver`? Trigger é à prova de caminhos que escrevem direto no DB; observer é mais idiomático em Laravel e testável. Recomendação: observer, com comentário explícito.
4. **§6.1**: janela de dual-write durante a dissolução de `post_embeddings` — 7 dias em staging é suficiente, ou queremos rodar em produção um tempo antes de dropar?
5. **§7/rastreabilidade**: o campo `recommendation_logs.scores_breakdown` é `jsonb`. Se quisermos consultas analíticas frequentes sobre scores parciais (ex.: "média de sim_lt por variante"), vale promover os campos para colunas típicas (`sim_lt_score decimal`, etc.). Hoje, jsonb é flexível e suficiente.
6. **§2 / guidelines**: `interaction_types.default_weight` e `half_life_hours` no DB **duplicam** os valores também configuráveis em `config/recommendation.php` (US-020). Qual é a fonte da verdade? Recomendação: config em runtime ganha; DB carrega apenas o **default inicial** (seed) — campo serve para documentação e fallback.
