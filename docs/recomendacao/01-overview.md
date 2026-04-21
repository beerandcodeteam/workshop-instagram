# Sistema de Recomendação — Overview

## 1. Sumário executivo

O Workshop Instagram é um clone simplificado do Instagram (Laravel 13 + Livewire 4) cujo propósito é servir de cenário didático para um sistema de recomendação baseado em **embeddings multimodais do Gemini**. A versão atual já possui o esqueleto: cada post é embeddado (texto + imagens/vídeo via `gemini-embedding-2-preview`, 1536 dimensões) e armazenado em `post_embeddings` com índice HNSW em `pgvector`; o usuário tem um único vetor `users.embedding` calculado como média simples dos posts que curtiu; e o feed faz `ORDER BY embedding <=> ?::vector` quando o usuário tem centroide, ou cai pro cronológico quando não tem.

Esse ponto de partida é funcional mas ingênuo: um único vetor por usuário não separa gostos antigos de gostos recentes, não desaprende quando o interesse muda, não pune conteúdo que o usuário pula ou esconde, não garante diversidade, e não sabe lidar com cold-start. Além disso, o único sinal capturado hoje é **like** — não há comentário, compartilhamento, dwell time, hide ou report entrando na equação, então o modelo tem uma visão míope do usuário.

Este documento descreve o **estado alvo**: um sistema de recomendação em dois estágios (*candidate generation* + *ranking*), com representação do usuário **multi-vetor** (long-term, short-term, avoid, clusters de interesse), sinais de interação ricos (positivos e negativos, explícitos e implícitos), observabilidade de cada decisão de ranking, e um feedback loop mensurável. O ganho esperado é um feed que (a) reage em segundos a interações da sessão, (b) evita conteúdo indesejado, (c) preserva diversidade para não virar câmara de eco, e (d) fornece métricas objetivas (CTR, dwell, diversidade, cobertura) para que o workshop possa iterar sobre decisões concretas.

## 2. Estado atual

Snapshot do que já existe no código em `2026-04-21`:

**Stack e infraestrutura**
- Laravel 13 / PHP 8.5, Livewire 4, Pest 4, Tailwind 4, Sail/Docker.
- PostgreSQL com extensão `pgvector` (ativada via `Schema::ensureVectorExtensionExists()`).
- Armazenamento de mídia: MinIO local (S3-compatível).
- Queue: driver **database** (`config/queue.php` — não há Redis configurado para queue, nem Horizon instalado).
- Redis: cliente PHP configurado no `.env` (`REDIS_CLIENT=phpredis`) mas **não usado** como store de cache ou queue hoje (`CACHE_STORE=database`, `QUEUE_CONNECTION=database`).
- SDK de IA instalado: `laravel/ai ^0.6` (presente no `composer.json`), mas o código **não** usa essa SDK — a integração está feita manualmente via `Http::post()` em `App\Services\GeminiEmbeddingService`.

**Modelo de domínio** (`app/Models/`)
- `Post` — `user_id`, `post_type_id`, `body`. Relações: `author`, `type`, `media`, `likes`, `comments`, `postEmbeddings`.
- `PostMedia` — `post_id`, `file_path`, `sort_order`.
- `PostType` — lookup (`text`, `image`, `video`).
- `Like` — `(user_id, post_id)` único.
- `Comment` — `user_id`, `post_id`, `body` (flat, sem edição, sem reply).
- `User` — `name`, `email`, `password`, `embedding` (vector(1536) nullable).
- `PostEmbedding` — `post_id`, `embedding` (vector(1536)), índice HNSW com `vector_cosine_ops`.

**Embedding pipeline (já funcional)**
- `App\Services\GeminiEmbeddingService::embed(array $parts, string $task_type = 'RETRIEVAL_DOCUMENT')` — faz POST direto para `generativelanguage.googleapis.com/.../gemini-embedding-2-preview:embedContent` com `output_dimensionality=1536`. Aceita multimodal: `parts` pode conter `['text' => ...]` e `['inline_data' => ['mime_type' => ..., 'data' => base64(bytes)]]`.
- `App\Jobs\GeneratePostEmbeddingJob` — monta `parts` com o body + cada media item baixado do disco como `inline_data`, chama o service, persiste em `post_embeddings`. Dispatch **síncrono** a partir do `PostObserver::created`.
- `App\Observers\PostObserver` — `#[ObservedBy]` em `Post`, escuta `created` e roda `dispatch_sync(GeneratePostEmbeddingJob)`.
- `App\Jobs\CalculateUserCentroidJob` — pega todos os posts curtidos pelo usuário, carrega seus embeddings, calcula **média aritmética simples** (sem pesos, sem decay) e grava em `users.embedding`.
- `App\Observers\LikeObserver` — em `created` e `deleted` do `Like`, dispara `CalculateUserCentroidJob` (fila padrão).
- Comando `app:generate-post-embeddings` — backfill para seed/demo.

**Feed atual** (`app/Livewire/Pages/Feed/Index.php`)
- Ordena por similaridade cosseno (`post_embeddings.embedding <=> ?::vector`) quando `auth()->user()->embedding` existe.
- Fallback cronológico (`latest('posts.created_at')`) quando o usuário ainda não tem centroide.
- Paginação via `perPage` crescente (10, 20, 30...) controlada pelo `loadMore()`.
- `JOIN post_embeddings` descarta silenciosamente posts sem embedding.

**O que NÃO existe ainda**
- Registro de **comentário como sinal positivo** para o centroide (só likes entram).
- Sinais de **compartilhamento, visualização, dwell time, skip, hide, report** — nenhum está modelado.
- Qualquer noção de **vetor negativo / avoid**.
- Separação **short-term vs long-term**.
- **Clusters de interesse** (multi-vetor).
- **Trending pool**, **following feed** (following não existe), **locality pool**.
- **Ranking de segundo estágio** (hoje é um único ANN + ordenação direta).
- **MMR / penalidade de diversidade**, **quota por criador**, filtro de **já visto**.
- **Cache Redis** de user embeddings.
- **Horizon**.
- **Logs estruturados de ranking** / debug de "por que recomendei isso".
- **Baseline aleatório** de 1%.
- Campos de moderação/block: sem tabela de `blocks`, `hides`, `reports`.

## 3. Estado alvo

Arquitetura de dois estágios alimentada por sinais ricos, com representação multi-vetor do usuário e observabilidade em cada etapa.

```
┌──────────────────────────────────────────────────────────────────────────────┐
│                               Captura de sinais                              │
│                                                                              │
│  like / comment / share / view / dwell / skip / hide / report                │
│         │                                                                    │
│         ▼                                                                    │
│   InteractionEvent  ──►  interactions (tabela append-only)                   │
│         │                                                                    │
│         ├──►  Redis: session:user:{id} (short-term buffer)                   │
│         └──►  dispatch RefreshShortTermJob  (assíncrono, fila "realtime")    │
└──────────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌──────────────────────────────────────────────────────────────────────────────┐
│                     Representação do usuário (multi-vetor)                   │
│                                                                              │
│  ┌────────────────┐  ┌────────────────┐  ┌────────────────┐  ┌────────────┐  │
│  │ long_term      │  │ short_term     │  │ avoid          │  │ clusters   │  │
│  │ (90–180d, HL30d│  │ (24–48h, HL6h) │  │ (hide/report,  │  │ (k-means,  │  │
│  │  decay lento)  │  │ decay rápido,  │  │  skip <1s)     │  │  3–7       │  │
│  │                │  │ cache Redis)   │  │                │  │  centroides│  │
│  └────────────────┘  └────────────────┘  └────────────────┘  └────────────┘  │
│        Postgres            Redis               Postgres          Postgres    │
└──────────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌──────────────────────────────────────────────────────────────────────────────┐
│                     Stage 1 — Candidate Generation (~1000)                   │
│                                                                              │
│   ┌──────────────┐  ┌──────────────┐  ┌──────────────┐  ┌─────────────────┐  │
│   │ ANN pgvector │  │ Trending     │  │ Following    │  │ Locality /      │  │
│   │ (300–500 por │  │ (top 100 por │  │ (posts de    │  │ Exploration     │  │
│   │  cluster/LT/ │  │  engagement  │  │  quem segue) │  │ (random 5% /    │  │
│   │  ST)         │  │  recente)    │  │  [FUTURO]    │  │  cold-start)    │  │
│   └──────────────┘  └──────────────┘  └──────────────┘  └─────────────────┘  │
│                             │                                                │
│                             ▼                                                │
│                    Union + dedup → pool ~1000                                │
└──────────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌──────────────────────────────────────────────────────────────────────────────┐
│                        Stage 2 — Ranking & Re-rank                           │
│                                                                              │
│   Score = α · cos(p, LT) + (1-α) · cos(p, ST)      (α dinâmico 0.3..0.8)     │
│         - β · cos(p, AVOID)          (penalidade)                            │
│         + γ · recency_boost                                                  │
│         + δ · trending_boost                                                 │
│         + ε · context_boost (hora, dispositivo, dia)                         │
│                                                                              │
│   Filtros duros: já visto, autor bloqueado, autor reportado, post reportado  │
│   MMR re-rank (λ=0.7) para diversidade                                       │
│   Quota por criador: máx. N posts do mesmo autor no top-K                    │
└──────────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌──────────────────────────────────────────────────────────────────────────────┐
│                         Resposta + Observabilidade                           │
│                                                                              │
│   Feed (paginado) + RankingTrace (por post: source, scores parciais, pos)   │
│         │                                                                    │
│         ├──►  ranking_traces (Postgres, TTL 7d)                              │
│         └──►  Metrics (Prometheus/log): CTR, dwell médio, Gini, cobertura    │
│                                                                              │
│   1% do tráfego → random serving (baseline não-enviesado)                    │
└──────────────────────────────────────────────────────────────────────────────┘
```

Componentes novos em relação ao estado atual:
- Tabela `interactions` (append-only, todos os sinais), substituindo o uso exclusivo de `likes` como sinal.
- Colunas `users.long_term_embedding`, `users.short_term_embedding`, `users.avoid_embedding` (ou tabela `user_embeddings` indexada por `(user_id, kind)`).
- Tabela `user_interest_clusters` (`user_id`, `cluster_index`, `centroid vector(1536)`, `weight`).
- Redis: keys `rec:user:{id}:short_term`, `rec:user:{id}:seen` (HyperLogLog ou set com TTL), `rec:trending:global`.
- Horizon para gerenciar múltiplas filas (`realtime`, `embeddings`, `clusters`, `longterm`).
- Tabela `ranking_traces` para debug/observabilidade.
- Service layer: `RecommendationService`, `CandidateGenerator`, `Ranker`, `UserVectorService`.

## 4. Entidades e conceitos

**Post embedding (já existe)** — vetor de 1536 dimensões produzido pelo Gemini a partir do texto + mídia do post. Task type `RETRIEVAL_DOCUMENT`. Armazenado em `post_embeddings.embedding` com índice HNSW cosseno. Imutável: se o post mudar, geramos um novo embedding (não atualiza in-place, para não quebrar comparações históricas).

**User long-term embedding (LT)** — vetor 1536 representando o gosto estável do usuário ao longo dos últimos 90–180 dias. Calculado como **média ponderada** dos embeddings de posts com interação positiva, onde o peso combina (a) peso do sinal (like, comment, share) e (b) decay exponencial com half-life ~30 dias. Task type semântico: `RETRIEVAL_QUERY` (é um query vector, não um document). Atualizado em batch diário.

**User short-term embedding (ST)** — vetor 1536 representando o gosto **da sessão atual + últimas 24–48h**. Mesma fórmula de média ponderada, mas com half-life ~6h. Atualizado **em tempo real** a cada interação positiva (via Redis) e persistido no Postgres a cada N interações ou via job debounced.

**User avoid embedding (AV)** — vetor 1536 agregando interações **negativas fortes** (hide, report, skip <1s repetido no mesmo criador). Usado como **penalidade no score** (subtrai `β · cos(p, AV)`), não como substituição direta. Não deve dominar o ranking — caso contrário o usuário fica preso em "tudo que não é o que ele odeia". Atualizado em batch.

**User interest clusters** — conjunto de 3 a 7 centroides obtidos por **k-means** rodado sobre os embeddings de posts curtidos nos últimos 90 dias. Modela o fato de que um usuário tem gostos múltiplos e distintos (ex.: "fotos de gatos" e "política nacional" não deveriam se misturar em um único vetor médio). Usado como múltiplas fontes no candidate generation. Recalculado semanalmente ou quando `|likes_since_last_cluster| > 20`.

**Candidate generation (Stage 1)** — etapa de **recall**: trazer ~1000 posts candidatos, barato e amplo, de múltiplas fontes (ANN por LT, ANN por ST, ANN por cada cluster, trending, following quando aplicável). Falso positivo aqui é barato; falso negativo (não trazer um bom post) é caro.

**Ranking (Stage 2)** — etapa de **precisão**: ordenar os ~1000 candidatos via score composto e re-rankeá-los para diversidade, quotas e filtros. Aqui é onde refletimos todos os sinais combinados.

**MMR (Maximal Marginal Relevance)** — algoritmo de re-rank que balanceia relevância e diversidade: `MMR(d) = λ · sim(d, query) - (1-λ) · max_{d' ∈ selected} sim(d, d')`. Com λ=0.7, priorizamos relevância mas penalizamos similaridade com posts já selecionados, quebrando blocos homogêneos.

**Quota por criador** — no top-K, no máximo N posts do mesmo autor (ex.: N=2 em 20). Evita que um criador prolífico domine o feed.

**Filtro "já visto"** — posts que o usuário já viu nas últimas M horas (ex.: 48h) ou já interagiu não aparecem. Armazenado em Redis como set ou HyperLogLog por usuário.

**Boost contextual** — ajustes no score baseados em hora do dia, dia da semana, tipo de dispositivo — ex.: vídeos curtos ganham boost à noite em mobile.

**α (alfa) dinâmico** — peso entre LT e ST no score. Em sessão longa com sinais fortes, `α → 0.3` (mais ST). Em cold-session ou usuário antigo sem interação recente, `α → 0.8` (mais LT). `[DECISÃO PENDENTE: fórmula exata do α dinâmico — heurística simples vs. modelo aprendido]`.

**Ranking trace** — registro estruturado, por requisição de feed, de cada post retornado: qual fonte gerou o candidato, scores parciais (LT, ST, AVOID, boosts), posição final, e se houve interação. Base do "por que recomendei isso" e do ciclo de avaliação offline.

**Random serving baseline** — 1% das requisições retornam um feed ordenado aleatoriamente (ou puramente cronológico). Serve como grupo de controle para estimar uplift real do sistema de recomendação sem o viés de que o modelo já favoreceu certos posts.

## 5. Core workflows

### 5.1. Criação de post

1. Autor envia post via `CreatePostModal` (Livewire). Salva `Post` + `PostMedia` rows.
2. `PostObserver::created` dispara `GeneratePostEmbeddingJob` (hoje sync — **mudar para queue `embeddings`**, retry com backoff).
3. Job carrega `body` + bytes de cada mídia, monta `parts` multimodal, chama `GeminiEmbeddingService::embed(parts, 'RETRIEVAL_DOCUMENT')`.
4. Persiste em `post_embeddings` (cascade on delete já existente).
5. Post entra no **índice HNSW** automaticamente (pgvector cuida disso no INSERT).
6. Post fica imediatamente elegível para candidate generation via ANN. Não fica elegível para trending até acumular N engajamentos.

### 5.2. Interação positiva (like/comentário/compartilhamento)

1. Usuário interage via componente Livewire (`post.like-button`, `post.comments`, botão share).
2. Cria linha em `likes` / `comments` (já existe) **e também em `interactions`** com `(user_id, post_id, kind, weight, occurred_at)`. Dual-write para não quebrar a UI atual.
3. Observer do `Interaction` (novo) faz:
   - Append no buffer Redis `rec:user:{id}:short_term` (lista circular dos últimos 50 eventos).
   - Dispatch `RefreshShortTermEmbeddingJob` na fila `realtime` **com debounce** (não recalcula a cada clique; aglutina janela de 5s).
4. `RefreshShortTermEmbeddingJob`:
   - Pega todas as `interactions` positivas do usuário nas últimas 48h.
   - Calcula média ponderada com decay exponencial (half-life 6h) e pesos por sinal (ver §6).
   - Persiste em `users.short_term_embedding` e `rec:user:{id}:short_term` (Redis, TTL 1h para cache warm).
5. LT **não** é recalculado aqui — só no batch diário. Isso é intencional: LT deve ser estável.

### 5.3. Interação negativa (hide/skip/report)

1. UI envia o sinal para `InteractionController@store` (ou Livewire action).
2. Insere em `interactions` com `kind` adequado. `hide` e `report` são **explícitos**; `skip` é **implícito** e só conta se `dwell_ms < 1000` e o scroll passou por completo.
3. `report` adicionalmente:
   - Cria `reports(user_id, post_id, reason)` (tabela nova).
   - Marca `post.reports_count` — após threshold, o post sai do pool global (filtro duro).
4. Dispatch `RefreshAvoidEmbeddingJob` (queue `clusters`, baixa prioridade).
5. Job recalcula `users.avoid_embedding` como média ponderada dos posts com interação negativa nos últimos 90 dias (pesos em §6).
6. **Importante**: o avoid nunca é subtraído diretamente do LT/ST. Ele entra como **penalidade no score de ranking** (`- β · cos(p, AV)`).

### 5.4. Dwell time tracking

1. Frontend mede tempo entre o post entrar na viewport (IntersectionObserver ≥50% visível) e sair.
2. Eventos são bufferizados no cliente e enviados em batch a cada N segundos ou no `beforeunload` via `navigator.sendBeacon`.
3. Backend recebe eventos `{post_id, dwell_ms, entered_at, left_at}` e grava em `interactions` com `kind='view'` + `weight` calculado por curva:
   - `< 1000ms` → sinal negativo (`skip`, weight -0.3)
   - `1000–3000ms` → neutro (não grava)
   - `3000–10000ms` → positivo leve (weight 0.2–0.5, escala linear)
   - `10000–30000ms` → positivo médio (weight 0.5–0.8)
   - `> 30000ms` → positivo forte mas cap em 1.0 (para não explodir em posts de vídeo longo)
4. Não dispara job a cada evento — um job `AggregateViewSignalsJob` roda a cada 10min por usuário com atividade.
5. `[DECISÃO PENDENTE: curva exata de dwell time — validar com dados reais do workshop ou usar literatura]`.

### 5.5. Request do feed (end-to-end)

1. `GET /` → `pages::feed.index` (Livewire). Já autenticado.
2. Componente chama `RecommendationService::feedFor(User $user, int $page, int $pageSize)`.
3. Service decide modo:
   - Se `user->long_term_embedding` é null e `interactions_count < 5` → **cold-start path** (§5.7).
   - Se 1% A/B (hash determinístico por `user_id + dia`) → **random serving** (cronológico puro, para baseline).
   - Senão → **recommendation path** abaixo.
4. Recommendation path:
   - a. **Candidate Generation** (paralelo, cada um limitado):
     - `CandidateGenerator::annByLongTerm(user, 300)` — `ORDER BY embedding <=> LT`.
     - `CandidateGenerator::annByShortTerm(user, 200)` — `ORDER BY embedding <=> ST`.
     - `CandidateGenerator::annByClusters(user, 100 por cluster)` — um ANN por centroide de cluster.
     - `CandidateGenerator::trending(100)` — top posts por `interactions_count * decay` das últimas 24h (cached Redis 5min).
     - `CandidateGenerator::following(user, 200)` — se following existir `[FUTURO — fora do MVP]`.
   - b. Union + dedup por `post_id`, aplica **filtros duros** (já visto nas últimas 48h, autor bloqueado, post reportado acima do threshold, post do próprio usuário).
   - c. **Ranking**: computa para cada candidato:
     ```
     score = α · cos(p, LT)
           + (1-α) · cos(p, ST)
           - β · cos(p, AV)
           + γ · recency_boost(p.created_at)
           + δ · trending_boost(p.trending_score)
           + ε · context_boost(user.context)
     ```
   - d. **MMR re-rank** (λ=0.7) no top ~100 para diversificar.
   - e. **Quota por criador**: remove excesso do mesmo `author_id` (máx. N no top-K).
   - f. Seleciona `page*pageSize..((page+1)*pageSize)` e retorna.
5. Para cada post do resultado, grava `ranking_traces` (assíncrono, fila `traces`).
6. Marca como "visto" em `rec:user:{id}:seen` (Redis, TTL 48h).
7. Blade renderiza os posts (mesmo componente `post.card` atual).

Custo-alvo por request: < 80ms no P50 (ANN ~10ms + 3 consultas pequenas + ranking em memória).

### 5.6. Refresh de user embeddings

**Long-term (batch diário)**:
- Cronjob `app:refresh-long-term-embeddings` roda 03:00 BRT.
- Para cada usuário com atividade nas últimas 7 dias:
  - Pega todas as `interactions` positivas dos últimos 180 dias.
  - Calcula média ponderada com decay exponencial (half-life 30d) e pesos por sinal.
  - Persiste em `users.long_term_embedding`.
- Usuários sem interação nos últimos 30 dias não são recalculados (evita custo desnecessário).

**Short-term (tempo real, com debounce)**:
- Disparado pelo `InteractionObserver` na fila `realtime` (alta prioridade).
- Debounce de 5s via Redis lock `rec:user:{id}:st_lock` — se job já está pendente, novo dispatch é descartado.
- Janela: últimas 48h, half-life 6h.
- Persiste em Redis (cache quente) + Postgres (persistente).
- Em hot-path (§5.5), o service lê de Redis primeiro; se miss, lê de Postgres e re-aquece.

**Clusters (semanal ou gatilho de volume)**:
- `app:refresh-interest-clusters` roda semanalmente ou quando `interactions_since_last_cluster > 20`.
- Roda k-means (k=3..7, escolhido via silhouette score) sobre embeddings de posts com sinal positivo nos últimos 90 dias.
- Persiste em `user_interest_clusters`.

**Avoid (diário)**:
- Piggyback no job do LT: recalcula `avoid_embedding` a partir das interações negativas dos últimos 90 dias.

### 5.7. Cold start

**Usuário novo** (sem interações ou < 5 interações):
- Feed = **trending global** (top posts por engajamento normalizado das últimas 24h).
- Intercalado com **posts mais recentes** (slot 1 a cada 5).
- `users.long_term_embedding = NULL`; o sistema **não** tenta calcular centroide com 1 ou 2 likes (ruído puro).
- Após 5 interações positivas, promove para recommendation path — mas com α alto (0.3) porque ST já é um sinal confiável.

**Post novo** (sem interações):
- Já é elegível para ANN via `post_embeddings` (o embedding é gerado na criação).
- **Não** é elegível para trending até ter engajamento.
- Para evitar que posts novos de autores desconhecidos sumam para sempre, aplicar um **exploration bonus**: γ (recency_boost) com half-life de 6h dá vantagem temporária a posts recentes.

**Autor novo** (primeiro post):
- Sem boost especial neste MVP. Se o conteúdo for bom (similar ao gosto do viewer), ANN traz naturalmente.
- `[DECISÃO PENDENTE: adicionar bonus explícito para "creator discovery" (primeiros 3 posts de um autor novo)?]`

## 6. Sinais e pesos

Todos os sinais gravam em `interactions(user_id, post_id, kind, weight, occurred_at, context jsonb)`. Pesos abaixo são valores iniciais calibráveis.

| Sinal               | Tipo       | Peso  | Decay (half-life) | Usado em      | Observações                                                  |
|---------------------|------------|-------|-------------------|---------------|--------------------------------------------------------------|
| `like`              | positivo   | +1.0  | LT: 30d / ST: 6h  | LT, ST        | Já capturado. Base do sistema.                               |
| `comment`           | positivo   | +1.5  | LT: 30d / ST: 6h  | LT, ST        | Engajamento mais alto que like.                              |
| `share`             | positivo   | +2.0  | LT: 30d / ST: 6h  | LT, ST        | Endosso público — sinal mais forte de aprovação.             |
| `view_3s`           | implícito+ | +0.3  | ST: 6h            | ST            | 3s–10s de dwell. Não entra no LT (muito ruidoso).            |
| `view_10s`          | implícito+ | +0.5  | ST: 6h            | ST            | 10s–30s de dwell.                                            |
| `view_30s`          | implícito+ | +0.8  | ST: 6h / LT: 30d  | LT, ST        | >30s é sinal forte — entra inclusive no LT.                  |
| `skip_fast`         | implícito- | -0.3  | ST: 6h            | AVOID         | <1s de dwell, usuário rolou rápido.                          |
| `hide`              | negativo   | -1.5  | LT: 30d           | AVOID         | "não quero ver isso" explícito.                              |
| `report`            | negativo+  | -3.0  | LT: 90d           | AVOID + HARD  | Também entra em filtro duro se cross-thresholded.            |
| `author_block`      | negativo+  | n/a   | permanente        | HARD          | Filtro duro: posts do autor nunca aparecem.                  |
| `unlike` (toggle)   | reverso    | -0.5  | imediato          | LT, ST        | Reverte parcialmente o like original (não zera — user mudou de ideia, não é como se nunca tivesse curtido). |

**Regras gerais de agregação**:
- Peso efetivo de uma interação no tempo `t` (avaliado agora em `T`): `w_eff = w_base * exp(-ln(2) * (T - t) / half_life)`.
- Vetor agregado (LT, ST, AV) = `Σ (w_eff_i * post_embedding_i) / Σ w_eff_i`.
- Se `Σ w_eff_i < threshold` (ex.: 2.0 para ST, 5.0 para LT), o vetor é `NULL` e o sistema cai para fallback (trending/cronológico).
- Vetor sempre é **L2-normalizado** antes de persistir — usamos cosseno, distância cosseno é `1 - (u·v)/(||u||·||v||)`, e normalizar evita recálculo repetido.

**Contexto registrado junto da interação** (campo `context jsonb`):
- `device` (mobile/desktop), `hour_of_day`, `day_of_week`, `session_id`, `feed_source` (qual fonte do candidate gen trouxe o post), `feed_position` (posição no feed quando ocorreu). Usado para boosts contextuais e análises offline.

## 7. Decisões técnicas principais

**Por que 1536 dimensões?**
Já é o que o projeto usa (`config('services.gemini.embedding.dimensions') = 1536`). Gemini 2 preview suporta `output_dimensionality` até 3072, mas 1536 é um ótimo balanço entre qualidade semântica e custo de armazenamento/ANN. Migração seria custosa (reembeddar tudo) — mantemos.

**Por que pgvector e não Qdrant/Pinecone/Weaviate?**
- pgvector já está instalado e funcionando (`Schema::ensureVectorExtensionExists()`, HNSW index em produção).
- Mantemos **uma única fonte de verdade transacional**: embedding, post, likes, tudo no mesmo Postgres, joináveis em SQL. Elimina drift entre sistemas e complexidade de sync.
- HNSW em pgvector escala bem até 10M+ vetores — muito além do tamanho do dataset do workshop (1250 posts seed). Em produção real, repensaríamos.
- Trade-off: queries multi-vetor (ANN por LT + ANN por ST + ANN por clusters) são múltiplas consultas SQL. Qdrant faria em uma. Não compensa migrar.

**Por que Redis para short-term embedding?**
- Short-term é atualizado a cada interação — hot path. Escrita no Postgres a cada clique é desperdício e contenção.
- Redis dá <1ms de leitura no hot-path do feed.
- Persistimos também em Postgres como backup (para sobreviver a restart do Redis).
- Redis também guarda `seen` set (filtro "já visto"), que seria caro no Postgres.

**Por que two-stage retrieval?**
- **Recall primeiro, precision depois** é o padrão da indústria (YouTube, TikTok, Pinterest). Stage 1 é barato e amplo; Stage 2 é caro e preciso sobre um conjunto pequeno.
- Rankear todos os 1250 posts do catálogo é viável hoje (workshop), mas inviável em produção real — e o workshop deve ensinar a arquitetura correta.
- Permite encaixar **múltiplas fontes** naturalmente (ANN + trending + following + explore), coisa que um ranking monolítico não faz bem.

**Por que multi-vetor (LT + ST + avoid + clusters) em vez de um único centroide ponderado?**
- Um único vetor mistura "gosto de ontem" com "gosto de 3 meses atrás" — decay resolve parcialmente mas perde informação.
- Gostos reais são **multimodais** (ex.: gosto de gatos E de Fórmula 1 — a média fica em "animais correndo", que é ruim).
- Avoid como penalidade (não subtração no LT) preserva o positivo "puro" e dá controle explícito da força da aversão via β.

**Por que Horizon?**
Porque vamos ter múltiplas filas com prioridades distintas: `realtime` (short-term, baixa latência), `embeddings` (novo post, médio), `clusters` (k-means, pesado, baixa prioridade), `traces` (ranking traces, fire-and-forget). Driver `database` atual funciona mas não dá observabilidade.

**Por que não usar `laravel/ai` SDK hoje?**
A SDK está instalada mas a chamada direta via `Http::post()` em `GeminiEmbeddingService` é simples, estável, e dá controle total. `[DECISÃO PENDENTE: migrar para laravel/ai — a SDK tem suporte a multimodal embeddings com task types? Validar e decidir]`.

**Por que task types Gemini diferentes para post vs user?**
- `RETRIEVAL_DOCUMENT` para post embeddings (são o "conteúdo indexado").
- `RETRIEVAL_QUERY` para user vectors quando usados para buscar (é uma "consulta"). Mudança pequena mas o Gemini otimiza o espaço vetorial para essa assimetria.
- `CLUSTERING` quando rodamos k-means nos embeddings dos posts (roda via API separada ou mantemos `RETRIEVAL_DOCUMENT` e aceitamos perda pequena).
- `[DECISÃO PENDENTE: a média/centroide do usuário também deve ser regerada em task_type=QUERY, ou usamos o que já temos em DOCUMENT? Trade-off: gera custo extra de API, ganho marginal]`.

**Por que random serving em 1%?**
Sem baseline não-enviesado, CTR e dwell do sistema viram métricas circulares ("o modelo favorece posts, usuários veem mais esses posts, CTR sobe"). 1% de feed aleatório nos dá um grupo de controle para estimar uplift real.

## 8. Trade-offs conhecidos

**Custo de embedding**: cada post ocupa 1 chamada Gemini (texto + imagens base64). Posts com 10 imagens pesam. Mitigação: cache de embedding por hash do conteúdo; não reembeddar em update se mídia não mudou. Não temos isso hoje.

**Cold posts em catálogo pequeno**: com 1250 posts, o ANN convergirá rápido para um conjunto "quente" de posts — posts novos ou nichados tendem a ser engolidos. O exploration bonus γ e a quota por criador ajudam mas não resolvem. Em produção real com milhões de posts isso é menos problema.

**Avoid pode ficar dominante em usuários "reclamões"**: se um usuário esconde muito conteúdo, o avoid puxa o score para baixo em muitos candidatos. β fixo não dá conta. `[DECISÃO PENDENTE: β adaptativo por usuário ou cap no contribuição do avoid?]`.

**k-means precisa de dados**: clusters só fazem sentido com ≥30 interações positivas. Usuários abaixo desse threshold caem no path LT+ST sem cluster. Não é ruim — só incremental.

**Decay discreto vs. contínuo**: estamos modelando decay como `exp(-λt)` no momento do recompute — mas o recompute só roda diariamente (LT) ou em debounce (ST). Entre dois recomputes, o vetor não decay. Em prática é aceitável — LT é estável por design, ST é recomputado em segundos.

**Diversidade vs. relevância**: MMR com λ=0.7 é um compromisso. Em usuários com gosto muito específico, MMR pode injetar "ruído indesejado" em nome da diversidade. Monitorar via métricas de satisfação por segmento.

**Lookahead bias em avaliação offline**: se treinamos com interações passadas e avaliamos em um holdout temporal, precisamos tomar cuidado para não vazar informação "do futuro" nos clusters/LT. Backfill respeitoso de tempo é obrigatório.

**Performance do multi-ANN**: rodar 3–7 ANNs (um por cluster) + LT + ST em cada request é N+2 queries a pgvector. Mitigação: cachear candidatos por usuário por N minutos em Redis (invalidando em interação forte). `[DECISÃO PENDENTE: TTL do cache de candidatos — 2min? 5min? 15min?]`.

**Sem following**: não temos grafo social. Isso elimina um sinal forte ("o que meus amigos curtem"). Fora do escopo do workshop — declarado out-of-scope em `docs/project-description.md`.

**Viés de feedback loop**: o modelo treina no que o modelo mostrou — o que não foi mostrado nunca vira sinal. O random serving 1% mitiga parcialmente, mas não resolve completamente.

**Privacidade**: estamos armazenando vetores que podem representar preferências sensíveis do usuário. Em produção real, política de retenção, anonimização e direito ao esquecimento (LGPD) precisam ser endereçados. Workshop: fora de escopo.

## 9. Métricas de sucesso

**Engajamento (por usuário, rolling 7d)**
- **CTR do feed**: (likes + comments + shares + views_30s) / posts_impressions. Alvo: +20% vs. cronológico puro.
- **Dwell time médio por post**: mediana + p75. Alvo: mediana ≥ 4s (workshop dataset).
- **Interactions per session**: total de sinais positivos por sessão. Alvo: crescente.

**Diversidade**
- **Gini coefficient dos autores no feed**: 0 = totalmente uniforme, 1 = um só autor. Alvo: < 0.5.
- **Cluster coverage**: % dos clusters de interesse do usuário que são representados no top-20 do feed. Alvo: ≥ 70%.

**Cobertura do catálogo**
- **% de posts do catálogo impresso em ≥1 feed** na última semana. Alvo: ≥ 60% (evita concentrar tudo em 50 posts virais).
- **Cauda longa**: % de impressões que vão para posts fora do top-10% mais populares. Alvo: ≥ 30%.

**Novidade**
- **Median age do post impressioned**: idade mediana (created_at) dos posts que aparecem no feed. Alvo: <14 dias — não queremos um feed só de posts antigos.

**Qualidade negativa**
- **Hide rate**: hides / impressions. Alvo: < 1%.
- **Report rate**: reports / impressions. Alvo: < 0.1%.

**Sistema**
- **P50 latência do feed**: < 80ms.
- **P95**: < 250ms.
- **Taxa de erro do job `GeneratePostEmbeddingJob`**: < 0.5%.
- **Lag da fila `realtime`**: < 10s no P95 (short-term deve refletir interações rápido).

**Comparação com baseline (random 1%)**
- Uplift de CTR, dwell time, e retorno no dia seguinte (D1 return) do grupo "recommendation" vs. "random". Alvo: todas as métricas positivas e estatisticamente significativas.

## 10. Glossário

- **ANN (Approximate Nearest Neighbors)**: algoritmo que encontra os vetores "mais próximos" de um vetor de consulta de forma aproximada mas rápida. pgvector HNSW é um ANN.
- **HNSW (Hierarchical Navigable Small World)**: estrutura de dados em camadas que torna ANN em O(log n) no caso médio. Usada pelo índice `embedding_hnsw_idx` que já existe.
- **Cosine distance** (distância cosseno): `1 - cos(θ) = 1 - (u·v)/(||u||·||v||)`. No pgvector: operador `<=>`. Valor 0 = idênticos, 1 = ortogonais, 2 = opostos.
- **Centroide**: média de um conjunto de vetores. No nosso caso, a média ponderada de embeddings de posts com sinal positivo do usuário.
- **Embedding**: vetor de números (aqui 1536 floats) que representa um conteúdo num espaço semântico. Conteúdos similares ficam próximos no espaço.
- **Task type (Gemini)**: parâmetro do modelo de embedding que diz "este vetor vai ser usado para quê" (documento indexado, query de busca, clustering). O modelo otimiza o espaço vetorial para cada caso.
- **Decay / half-life**: fator de esquecimento. Half-life de 30 dias significa que depois de 30 dias, o peso da interação cai pela metade. Após 60 dias, cai para 1/4. E assim por diante.
- **Dwell time**: tempo que um post ficou visível na tela do usuário. Sinal implícito de interesse.
- **Impression**: o post foi renderizado no feed do usuário (≠ visualizado; pode ter saído da tela sem ser visto).
- **Candidate generation**: trazer "um monte de candidatos" baratos, sem se preocupar com ordem perfeita.
- **Ranking**: ordenar os candidatos com cuidado, usando features mais caras.
- **Re-rank**: ajustar a ordem final após o ranking principal (aqui, MMR para diversidade).
- **MMR (Maximal Marginal Relevance)**: algoritmo que promove diversidade penalizando candidatos muito parecidos com os já selecionados.
- **Cold start**: situação em que não há dados suficientes (usuário novo ou post novo). Requer estratégia específica.
- **Baseline aleatório**: grupo de controle servido com feed aleatório/cronológico, para medir uplift real da recomendação.
- **Feedback loop**: efeito circular em que o modelo só aprende sobre o que o modelo mostrou — viés estrutural de sistemas de recomendação.
- **Gini coefficient**: medida de desigualdade. Aqui usado para diversidade de autores no feed.

---

## Perguntas em aberto

Lista consolidada de `[DECISÃO PENDENTE]` que surgiram no documento:

1. **Fórmula do α dinâmico** (§4): heurística baseada em "tempo desde última sessão" / "volume de sinais nas últimas 24h", ou modelo aprendido offline?
2. **Curva exata do dwell-time → peso** (§5.4): calibrar com dados do workshop ou adotar curva da literatura (Google/YouTube papers)?
3. **Creator discovery bonus** (§5.7): adicionar boost explícito aos 3 primeiros posts de um autor novo, ou deixar ANN resolver organicamente?
4. **Migração para SDK `laravel/ai`** (§7): a SDK oficial do Laravel cobre embeddings multimodais com task types, ou mantemos `GeminiEmbeddingService` custom?
5. **Task type para user vector** (§7): regerar embeddings do usuário em `RETRIEVAL_QUERY` em vez de reaproveitar a média de vetores `RETRIEVAL_DOCUMENT`? Custo extra de API vs. ganho de qualidade.
6. **Adaptatividade do β** (§8): β fixo global, β por usuário calibrado por volume de interações negativas, ou cap na contribuição do avoid?
7. **TTL do cache de candidatos em Redis** (§8): 2min, 5min, 15min? Invalidação em interação forte (like/hide) mas não em view?
8. **Estratégia de evolução do embedding do post** (§5.1): se um post tem caption editada (US-3.4), regeramos embedding? Mantemos histórico?
9. **Modelo de dados de `interactions`**: tabela única append-only com `kind` enumerado, ou tabelas separadas por tipo? Leitura: única é mais simples e indexável; separadas seguem as convenções existentes (`likes`, `comments`).
10. **Threshold para "posts reportados somem"**: quantos reports independentes antes de retirar o post do pool global?
