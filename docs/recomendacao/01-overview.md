# Sistema de Recomendação — Overview

> Documento de design. Nada aqui foi implementado ainda, exceto o que estiver explicitamente marcado como "já existe" na seção 2.

---

## 1. Sumário executivo

O Workshop Instagram hoje entrega um feed ordenado por similaridade vetorial simples: 
cada `Post` tem um embedding multimodal 
(texto + mídia) gerado pelo Gemini, cada `User` tem um centroide que é a média dos embeddings dos posts que curtiu, 
e `App\Livewire\Pages\Feed\Index` ordena os posts pelo operador `<=>` (distância cosseno) do pgvector. 
É um MVP didático e funcional, mas enviesado para um único sinal (like), sem nenhum sinal negativo, 
sem nenhuma noção de temporalidade (short-term vs long-term), sem diversidade, sem observabilidade de ranking, 
e sem separação entre geração de candidatos e re-ranqueamento.

Este documento descreve a evolução para um **sistema de recomendação de feed em dois estágios com representação 
multi-vetor do usuário**. Os três saltos principais sobre o estado atual são: (1) trocar o único centroide por um 
conjunto de vetores (`long_term`, `short_term`, `avoid`, e opcionalmente clusters de interesse); (2) introduzir 
uma camada de *candidate generation* que combina ANN search, trending, following e locality antes do ranking final; 
(3) capturar múltiplos sinais de interação ponderados e com decaimento temporal (like, comentário, share, dwell time, 
skip, hide, report), incluindo sinais negativos que hoje simplesmente não existem no banco.

O ganho esperado em relação ao estado atual: o feed deixa de ser "o vetor mais parecido com a média de tudo que você 
curtiu na vida" e passa a ser "um mix contextualizado de conteúdo alinhado ao seu interesse recente, com diversidade, 
sem repetir o que você já viu, sem autores que você escondeu, e com uma trilha explícita de por que cada post apareceu 
no seu feed". Em termos mensuráveis: aumento de CTR e dwell time médio, queda da taxa de skip rápido, aumento da 
cobertura de catálogo e da diversidade Gini (métricas definidas na seção 9).

---

## 2. Estado atual

Mapeamento do que já existe no repositório em `2026-04-22`, levantado lendo o código diretamente (não é especulação).

### Infraestrutura
- Laravel 13 (`laravel/framework: ^13.0`) + PHP 8.5 (`composer.json` pede `^8.3`, CLAUDE.md lista 8.5).
- Livewire 4 (class components, sem Volt).
- `laravel/ai: ^0.6.0` instalado, mas a integração atual com Gemini é feita via HTTP direto, não via SDK.
- PostgreSQL + **pgvector** habilitado (`Schema::ensureVectorExtensionExists()` na migration de `post_embeddings`).
- Sail (Docker) para dev, MinIO local / S3 prod para mídia.
- Queue driver default: **`database`** (`config/queue.php`). Redis está **configurado em `config/database.php`** mas não é usado como driver de fila. **Horizon não está instalado** (`composer.json` não lista).

### Tabelas e modelos
Já existentes (das migrations em `database/migrations/`):

| Tabela | Colunas relevantes |
|---|---|
| `users` | id, name, email, password, **`embedding vector(1536) NULL`** |
| `posts` | id, user_id, post_type_id, body, timestamps |
| `post_types` | id, name, slug, is_active — seed: `text`, `image`, `video` |
| `post_media` | id, post_id, file_path, sort_order |
| `likes` | id, user_id, post_id, unique(user_id, post_id) |
| `comments` | id, user_id, post_id, body |
| `post_embeddings` | id, post_id, **`embedding vector(1536)`**, **índice HNSW `vector_cosine_ops`** |

Models em `app/Models/`: `User`, `Post`, `PostType`, `PostMedia`, `Like`, `Comment`, `PostEmbedding`.

### Pipeline de embedding (já existe)
- `App\Services\GeminiEmbeddingService::embed($parts, $task_type = 'RETRIEVAL_DOCUMENT')` — chama `gemini-embedding-2-preview` com `output_dimensionality=1536`. Não usa `laravel/ai`; é `Http::post` direto.
- `App\Jobs\GeneratePostEmbeddingJob` — concatena `$post->body` como `parts[*].text` e cada `post_media` como `parts[*].inline_data` base64, e salva em `post_embeddings`.
- `App\Observers\PostObserver::created` — dispara o job com **`dispatch_sync`** (síncrono, na request do usuário; não é fila).
- `App\Console\Commands\GeneratePostEmbeddings` — backfill com `--force` e `--chunk`.
- `App\Jobs\CalculateCentroidJob` — recalcula `users.embedding` como **média aritmética simples (sem pesos, sem decay)** dos embeddings dos posts curtidos pelo usuário.
- `App\Observers\LikeObserver::created|deleted` — dispara `CalculateCentroidJob` (esse sim vai pra fila).

### Camada de feed (já existe)
`App\Livewire\Pages\Feed\Index::render()`:
```php
$query = Post::query()
    ->join('post_embeddings', 'post_embeddings.post_id', '=', 'posts.id');

if ($viewerCentroid) {
    $query->orderByRaw('post_embeddings.embedding <=> ?::vector', [$literal]);
} else {
    $query->latest('posts.created_at');
}
```
Paginação por `perPage += 10` (infinite scroll via `loadMore`). Não há deduplicação de posts já vistos, não há quota por autor, não há filtros de negócio, não há logging de ranking, não há distinção entre geração de candidatos e ranking.

### O que **não** existe e este documento propõe
- Sinais de comentário, share, view/dwell, skip, hide, report — nem tabelas, nem modelos, nem observers. A única interação capturada hoje é `likes`.
- Tabela de seguidores (`follows`) — fora do escopo do app base por design; não há "following feed".
- Qualquer noção de localização (`locality`) — não há coluna geo em `users` ou `posts`.
- Short-term embedding, avoid embedding, clusters — `users.embedding` é um único vetor.
- Camada de candidate generation — hoje o ANN é o único recurso.
- Camada de ranking (reordenação) — `ORDER BY <=>` é o score final.
- Cache Redis de embeddings — `users.embedding` é lido direto do Postgres a cada request.
- Logging estruturado de decisões de ranking, métricas agregadas, serving randômico baseline.

---

## 3. Estado alvo

Arquitetura em dois estágios com representação multi-vetor do usuário, feedback loop quente via Redis, e tudo que é caro (re-embed, centroide long-term, clusterização) em jobs assíncronos.

```
                                 ┌──────────────────────────────────────────────┐
                                 │                 Usuário                      │
                                 └──────────┬───────────────────────────────────┘
                                            │ (feed request / interação)
                                            ▼
                            ┌───────────────────────────────┐
                            │   Livewire / HTTP (web)       │
                            └───────┬────────────────┬──────┘
                                    │                │
                      feed request  │                │  interação (like/comment/
                                    │                │  share/dwell/skip/hide/report)
                                    ▼                ▼
                    ┌──────────────────────┐  ┌──────────────────────────────┐
                    │  FeedService         │  │  InteractionService          │
                    │  (orquestra 2 stages)│  │  - persiste evento           │
                    └──┬───────────────┬───┘  │  - atualiza short_term (Redis)│
                       │               │      │  - enfileira jobs            │
       Stage 1 ──────► │               │      └──────┬───────────────────────┘
       Candidate Gen   │               │             │
                       ▼               ▼             │
   ┌────────────┐ ┌──────────┐ ┌──────────┐ ┌─────────┐
   │ ANN pgvec. │ │ Trending │ │ Following│ │ Locality│
   │ 300-500    │ │  100     │ │   pool   │ │   pool  │
   └──────┬─────┘ └────┬─────┘ └────┬─────┘ └────┬────┘
          │            │            │            │
          └────────────┴──────┬─────┴────────────┘
                              ▼
                    ┌────────────────────┐
                    │ Candidate pool ~1k │
                    │   (dedup por id)   │
                    └─────────┬──────────┘
                              │ Stage 2 — Ranking
                              ▼
                    ┌─────────────────────────────────────┐
                    │  Ranker                             │
                    │  • score = α·sim_LT + (1-α)·sim_ST  │
                    │  • filtro avoid (distância mínima)  │
                    │  • MMR diversidade (λ=0.7)          │
                    │  • filtros: visto, bloqueado, quota │
                    │  • boost contextual (hora, device)  │
                    └─────────┬───────────────────────────┘
                              │
                              ▼
                    ┌────────────────────┐     ┌───────────────────┐
                    │ Feed ranqueado     │────►│ ranking_logs      │
                    │ (top N pro usuário)│     │ (score breakdown) │
                    └─────────┬──────────┘     └───────────────────┘
                              │
                              ▼
                          Resposta

 ─────────────────────────────────────────────────────────────────────────────

 Pipeline offline / assíncrono (Horizon)
 ─────────────────────────────────────────

  PostObserver::created ─► GeneratePostEmbeddingJob ─► post_embeddings (pgvector)

  InteractionEvent   ───► UpdateShortTermEmbeddingJob ─► Redis (TTL ~48h)
   (like/comm/share/      UpdateLongTermEmbeddingJob  ─► users.long_term_embedding
    dwell/skip/hide)      UpdateAvoidEmbeddingJob     ─► users.avoid_embedding

  Daily cron         ───► RefreshLongTermEmbeddingJob (decay + recompute)
  Daily cron         ───► RefreshInterestClustersJob  (k-means sobre positivos)
  Daily cron         ───► ComputeTrendingPoolJob      ─► Redis zset
  Hourly cron        ───► RollupInteractionMetricsJob ─► métricas agregadas

 ─────────────────────────────────────────────────────────────────────────────
```

Stack alvo, concretamente:

- **Laravel 13 / PHP 8.5**: mesma versão do projeto.
- **PostgreSQL + pgvector**: já em uso; estende-se as colunas vetoriais em `users` e cria novas tabelas.
- **Redis**: entra efetivamente como cache (não só config). Usado para `user_short_term_embedding`, trending pool, rate limit de re-embed, e feed pré-computado opcional. `[DECISÃO PENDENTE: mover QUEUE_CONNECTION de 'database' para 'redis'?]`
- **Laravel Horizon**: entra no projeto para monitorar as filas de embedding, atualização de vetores e clusterização. `[DECISÃO PENDENTE: instalar laravel/horizon ou seguir com queue database?]`
- **Gemini Embedding 2** (`gemini-embedding-2-preview`, 1536 dims): já em uso. Documento formaliza quais `task_type` usar em cada contexto.
- **laravel/ai (v0.6)**: já no `composer.json` mas não integrado. `[DECISÃO PENDENTE: migrar GeminiEmbeddingService para laravel/ai ou manter Http direto pela simplicidade didática?]`

---

## 4. Entidades e conceitos

### Post embedding (`post_embeddings.embedding`) — já existe
Vetor de 1536 dimensões gerado pelo `gemini-embedding-2-preview` a partir da concatenação multimodal de `parts`: o `body` do post como `text` e cada arquivo de `post_media` como `inline_data` base64. `task_type = RETRIEVAL_DOCUMENT`. Normalizado pelo próprio modelo. Índice HNSW com `vector_cosine_ops` já está criado.

### User long-term embedding (`users.long_term_embedding`) — substitui `users.embedding`
Vetor 1536-d que representa o interesse **estável** do usuário nos últimos 90-180 dias. Calculado como **média ponderada com decay exponencial** dos embeddings de posts com interação positiva, onde o peso é `w_signal · 2^(-Δt_dias / 30)`. Meia-vida ~30 dias: uma interação de 30 dias atrás vale metade de uma de hoje; uma de 60 dias atrás vale 1/4. Atualizado em batch diário (não a cada interação — para não instabilizar o vetor).

### User short-term embedding (`users.short_term_embedding` + cache Redis)
Vetor 1536-d que representa o **interesse de sessão** (últimas 24-48h, com ênfase na sessão atual). Meia-vida **~6h**. Atualizado a cada interação positiva via job leve que lê o vetor atual do Redis, aplica `v_new = γ·v_old + (1-γ)·v_post_interagido`, renormaliza e grava de volta no Redis com TTL de 48h. O Postgres mantém o último snapshot para cold restart do cache. Este é o vetor que mais influencia o ranking em sessões ativas.

### User avoid embedding (`users.avoid_embedding`)
Vetor 1536-d agregado de sinais **negativos fortes** (hide, skip rápido recorrente, report). **Usado como filtro, não como subtração.** A regra é: *se `cos(post, avoid) > θ_avoid` (ex.: 0.85), descarta o candidato*. Subtrair o avoid do user vector é tentador e matematicamente instável — produz vetores com norma pequena e comportamento errático em ANN. Um filtro binário por distância é mais previsível. `θ_avoid` é tunável e começa conservador.

### User interest clusters (`user_interest_clusters.*`) — roadmap
N vetores de 1536-d (3-7 por usuário) obtidos por **k-means sobre os embeddings dos posts com interação positiva** do usuário. Permitem representar interesses **disjuntos** ("gosto de futebol *e* de culinária, não da média dos dois"). O candidate generation faz ANN com cada centroide e mistura os resultados. Recalculado diariamente. **Entra como feature de roadmap** — a v1 do sistema pode rodar só com long/short/avoid.

### Candidate generation
Processo do Stage 1: **gerar ~1000 candidatos** a partir de múltiplas fontes, sem ranquear entre si; só deduplica por `post_id`. Fontes:
- **ANN pgvector** (300-500): query por similaridade cosseno contra `α·long_term + (1-α)·short_term`.
- **Trending pool** (100): posts com maior engajamento recente (última hora/dia). Pré-computado em Redis zset por cron.
- **Following pool**: posts de quem o usuário segue. `[DECISÃO PENDENTE: tabela follows não existe; incluir no roadmap ou deixar de fora da v1?]`
- **Locality pool**: posts locais. `[DECISÃO PENDENTE: não há coluna geo; deixar de fora da v1.]`

### Ranking
Processo do Stage 2: **reordenar** o pool de ~1000 candidatos com um score composto. A diferença chave em relação ao Stage 1 é que aqui entram features caras por candidato (scores parciais, diversidade MMR, filtros de negócio por par usuário-post, boosts contextuais). Roda in-memory em PHP, não em SQL.

### Score composto
Para cada candidato `p`:
```
score(p) = α · sim(p, long_term)
        + (1 - α) · sim(p, short_term)
        + β_trending · norm(trending_score)
        + β_following · is_from_followed
        + β_recency · decay(age_hours)
        - γ_seen · já_visto
        - γ_author · cota_do_autor_excedida
```
Onde `α` é **dinâmico por contexto**: sessão fresca (primeira request do dia) → `α=0.7` (favorece long-term); navegação ativa → `α=0.3` (favorece short-term); usuário cold start → `α=1.0` (só long-term ou popularidade).

### MMR (Maximal Marginal Relevance)
Reordenação final que equilibra relevância e diversidade:
```
MMR(p) = λ · score(p) - (1-λ) · max_{q ∈ S} sim(p, q)
```
Com `λ = 0.7`. Aplicado iterativamente: começa com o top-1 por score, depois a cada passo escolhe o candidato que maximiza MMR contra o conjunto `S` já selecionado. Evita o feed virar 10 posts do mesmo assunto.

### Feedback loop e observabilidade
Cada request do feed produz uma linha por candidato ranqueado em `ranking_logs`, com: `user_id`, `post_id`, `position`, `source` (ann|trending|following), `sim_long_term`, `sim_short_term`, `trending_score`, `mmr_penalty`, `final_score`, `served_at`. Essa tabela é a base de análises offline (CTR por posição, diversidade Gini, cobertura, correlação entre score e dwell realizado).

### Random serving baseline
Em **1% do tráfego** (por `user_id % 100 == 0` com salt rotativo), o feed é servido em ordem **aleatória uniforme** sobre candidatos de trending. Serve pra coletar sinais de engajamento **não-enviesados pelo próprio ranker** — fundamental pra evitar feedback loop fechado onde só aparece quem já aparecia.

### "Por que esse post?"
Para debug e transparência, cada item do feed carrega um campo `explain` na view (escondido em prod, visível com `?debug=ranking` em dev) com o breakdown do score e a fonte. Esse campo é lido diretamente do `ranking_logs` correspondente.

---

## 5. Core workflows

### 5.1. Criação de post

1. `App\Livewire\Post\CreateModal` persiste o `Post` e seus `post_media`.
2. `App\Observers\PostObserver::created` hoje dispara `dispatch_sync` — **proposta: trocar para `dispatch()` assíncrono** (fila `embeddings`) para não travar a request do usuário em ~1-3s de chamada ao Gemini.
3. `GeneratePostEmbeddingJob`:
   - Monta `parts` com `body` e cada `post_media` base64 (já implementado).
   - Chama `GeminiEmbeddingService::embed($parts, 'RETRIEVAL_DOCUMENT')`.
   - `INSERT INTO post_embeddings (post_id, embedding)`.
   - Em caso de erro (rate limit, timeout), retry exponencial com max 3 tentativas. Após 3 falhas, grava em `failed_jobs` e o post fica temporariamente invisível no feed ranqueado (ele só aparece após ter embedding, pelo `join` já existente).
4. O post aparece no feed **assim que o embedding é gerado**, não antes.

**Alteração a fazer no código existente:** `PostObserver::created` precisa trocar `dispatch_sync` por `dispatch` (fila). Hoje está síncrono e bloqueia a UI.

### 5.2. Interação positiva (like / comentário / compartilhamento)

1. `InteractionService::record(User $user, Post $post, InteractionType $type)` grava o evento em `interaction_events` (tabela nova — ver seção 6).
2. No mesmo método (dentro de uma `DB::transaction`), dispara **dois jobs**:
   - `UpdateShortTermEmbeddingJob($userId, $postId, $weight)` — na fila `realtime` (alta prioridade, baixa latência). Atualiza Redis.
   - `MarkForLongTermRecomputeJob($userId)` — só coloca um flag (ex.: Redis set `long_term:dirty`). O recálculo long-term acontece no cron diário.
3. `Like` continua existindo (é toggle e tem constraint unique), mas o `LikeObserver` atual — que dispara `CalculateCentroidJob` a cada like — **é substituído** pelo `InteractionService`. O centroide antigo é descontinuado em favor dos vetores separados (short/long).
4. Comentário e compartilhamento **não** têm tabela própria hoje (`Comment` existe; "share" não). `[DECISÃO PENDENTE: criar tabela shares ou modelar share como uma entry em interaction_events sem entidade própria?]`

### 5.3. Interação negativa (hide / skip / report)

1. **Hide** ("não quero ver"): botão novo no `PostCard`. `InteractionService::record(..., InteractionType::Hide)`.
   - Grava `interaction_events` com `weight = -3.0`.
   - Dispara `UpdateAvoidEmbeddingJob` (atualiza `users.avoid_embedding` como média exponencial dos hides).
   - Adiciona `post_id` no set Redis `user:{id}:hidden` com TTL longo (30 dias) — filtro duro no ranker.
2. **Skip** (<1s dwell): detectado client-side via Intersection Observer. Só conta se o post ficou <1s visível **e** o usuário já scrollou pelo menos 3 posts depois dele (evita falso positivo de scroll rápido passageiro).
   - Grava como evento com `weight = -0.3` (sinal fraco, pode ser ruído).
   - **Não** atualiza avoid imediatamente; avoid só é influenciado por skip se o mesmo autor/cluster acumular muitos skips.
3. **Report**:
   - `weight = -10.0` (máximo).
   - Ação imediata: adiciona `post_id` em `user:{id}:hidden` **e** encaminha para fila de moderação `[DECISÃO PENDENTE: não há modelo de moderação no projeto; começar com hide duro e log pro instrutor olhar manualmente no workshop?]`.

### 5.4. Dwell time tracking

1. Client-side (JS/Alpine) usa `IntersectionObserver` para detectar quando um post entra e sai do viewport com ≥50% de visibilidade.
2. Ao sair, calcula `dwell_ms = exit_ts - enter_ts` e faz POST batch (a cada 5 posts ou a cada 10s, o que vier primeiro) para `/api/dwell` com `[{post_id, dwell_ms, session_id}, ...]`.
3. `DwellEventController` valida e enfileira `RecordDwellEventsJob` (batch) na fila `ingest`.
4. O job converte `dwell_ms` em `weight` numa curva:
   ```
   dwell < 1000ms   → weight = -0.3  (skip)
   1000-3000ms      → weight = 0     (neutro, não registra)
   3000-10000ms     → weight = +0.5
   10000-30000ms    → weight = +1.0
   > 30000ms        → weight = +1.5  (saturando em 30s)
   ```
5. Só grava em `interaction_events` com `type = dwell` se `weight != 0`, pra não inundar a tabela.
6. Dwell positivo só atualiza short-term (não long-term) — é sinal implícito, mais ruidoso que like.

### 5.5. Request do feed (end-to-end)

Substitui inteiramente a lógica atual de `App\Livewire\Pages\Feed\Index::render()`.

```
1. Livewire Index::render()
   └─► FeedService::build(User $user, int $page, int $perPage)

2. FeedService::build():
   2.1. Lê short_term do Redis (fallback: users.short_term_embedding no Postgres, ou null).
   2.2. Lê users.long_term_embedding.
   2.3. Define α dinâmico (ver seção 4):
        - Se não há short_term: α = 1.0
        - Se sessão fresca (<10 interações na última hora): α = 0.7
        - Se sessão ativa: α = 0.3
   2.4. Computa vetor de consulta: q = normalize(α·long + (1-α)·short)

3. Stage 1 — Candidate Generation (paralelizável):
   3.1. ANN pgvector:
        SELECT p.id FROM posts p
          JOIN post_embeddings e ON e.post_id = p.id
          WHERE p.id NOT IN (hidden_set)
          ORDER BY e.embedding <=> q::vector
          LIMIT 500;
   3.2. Trending: ZREVRANGE trending:global 0 99 (Redis zset)
   3.3. Following: SELECT p.id FROM posts p WHERE p.user_id IN (followed_ids) LIMIT 200
        [DECISÃO PENDENTE: se não houver tabela follows na v1, pular esta fonte]
   3.4. Deduplica por post_id → pool ~800-1000.

4. Stage 2 — Ranking (in-memory em PHP):
   4.1. Carrega embeddings dos candidatos em um SELECT único.
   4.2. Carrega avoid_embedding do user e calcula cos(p, avoid) para cada candidato.
        Descarta candidatos com cos > θ_avoid (0.85).
   4.3. Aplica filtros de negócio:
        - post_id em seen_set dos últimos 7 dias (Redis) → descarta
        - user_id bloqueado pelo usuário → descarta
        - mesmo autor >3x nos 20 primeiros → descarta excedentes
   4.4. Calcula score composto para cada (seção 4).
   4.5. Aplica MMR (λ=0.7) iterativamente até ter perPage+buffer itens.
   4.6. Aplica boost contextual:
        - +0.05 se hora do dia coincide com histórico de uso
        - device mobile: leve penalidade em post só-texto longo
        [DECISÃO PENDENTE: que contexto capturar? pelo menos timezone do user.]
   4.7. Reordena pela soma final.

5. Logging:
   5.1. INSERT batch em ranking_logs (uma linha por post servido).
   5.2. Adiciona post_ids ao seen_set Redis com TTL 7 dias.

6. Retorna collection hidratada com relations já carregadas (author, type, media,
   withCount likes/comments) — igual ao Index atual faz.
```

### 5.6. Refresh de user embeddings

**Short-term** — em tempo real, a cada interação positiva:
```
1. UpdateShortTermEmbeddingJob($userId, $postId, $weight):
   1.1. v_old = Redis.GET short_term:{userId} (fallback Postgres ou zero vector)
   1.2. v_post = SELECT embedding FROM post_embeddings WHERE post_id = ?
   1.3. age_h = horas desde última atualização
   1.4. decay = 2^(-age_h / 6)   // half-life 6h
   1.5. v_new = normalize(decay · v_old + weight · v_post)
   1.6. Redis.SET short_term:{userId} v_new EX 172800  // 48h
   1.7. Async: snapshot no Postgres a cada N atualizações (amortização)
```

**Long-term** — cron diário (03:00 BRT):
```
1. RefreshLongTermEmbeddingsJob (para cada usuário marcado como dirty, chunk 100):
   1.1. Carrega todas as interaction_events dos últimos 180 dias onde weight > 0.
   1.2. Para cada evento: w_i = weight_signal · 2^(-age_days / 30)
   1.3. v_user = normalize(Σ w_i · embedding_i / Σ w_i)
   1.4. UPDATE users SET long_term_embedding = ?, long_term_updated_at = now()
```

Por que não atualizar long-term a cada interação? Porque (a) o long-term não precisa ser fresh — é a personalidade do usuário; (b) calcular ponderado com decay sobre 180 dias de eventos é pesado; (c) instabilizar o long-term a cada like prejudica a consistência do feed.

### 5.7. Cold start

**Usuário novo** (zero interações):
- `short_term = null`, `long_term = null`.
- Feed = 100% **trending pool** + diversidade MMR forte.
- Primeira seção da UI é "sugestões" com um seletor inicial de interesses (`[DECISÃO PENDENTE: fazer onboarding com seleção de tópicos para bootstrap do long-term?]`).
- Após ~5 interações positivas, short-term começa a dominar.
- Após ~20 interações positivas distribuídas em 3+ dias, long-term fica estável.

**Post novo** (tem embedding mas tem 0 interações):
- Embedding já existe — ANN funciona normalmente.
- Entra no candidate pool via ANN e via following (se aplicável).
- Não entra em trending até ter N views (natural).
- Para evitar que posts novos nunca sejam descobertos, **10% do pool de candidatos é reservado para posts criados nas últimas 24h**, sorteados uniformemente. Isso é um boost de exploration.

---

## 6. Sinais e pesos

Tabela nova proposta: `interaction_events` (captura todos os sinais, unifica o que hoje está em `likes` e `comments` + os novos).

```
interaction_events
  id                bigint PK
  user_id           bigint FK
  post_id           bigint FK
  type              varchar   -- like, comment, share, dwell, skip, hide, report
  weight            float     -- peso aplicado (já derivado do tipo + contexto)
  dwell_ms          int NULL  -- só pra type=dwell/skip
  session_id        varchar NULL
  created_at        timestamp
  INDEX (user_id, created_at)
  INDEX (post_id, type)
```

`likes` e `comments` **continuam existindo** (são entidades de domínio, têm UI própria: toggle de like, listagem de comentários). `interaction_events` é uma tabela de **eventos analíticos em append-only**, alimentada por observers em cima de `Like` / `Comment` / endpoints novos de dwell/hide/report.

### Pesos e decaimento

| Sinal | Peso base | Afeta LT | Afeta ST | Afeta Avoid | Decay | Observações |
|---|---:|:---:|:---:|:---:|---|---|
| Like | +1.0 | sim | sim | — | LT: half-life 30d; ST: half-life 6h | Toggle; unlike remove do agregado |
| Comentário | +2.5 | sim | sim | — | idem | Engajamento ativo, sinal forte |
| Compartilhamento | +4.0 | sim | sim | — | idem | Endosso público, sinal mais forte positivo |
| Dwell 3-10s | +0.5 | não | sim | — | ST only | Implícito, pode ter ruído |
| Dwell 10-30s | +1.0 | sim (fraco) | sim | — | LT weight ×0.3 | Strong implicit |
| Dwell >30s | +1.5 | sim (fraco) | sim | — | LT weight ×0.3 | Saturation em 30s |
| Skip (<1s) | -0.3 | não | não | levemente | só acumula | N skips do mesmo cluster puxa avoid |
| Hide | -3.0 | não | não | sim | 30d hard filter | Dispara filtro duro e atualiza avoid |
| Report | -10.0 | não | não | sim | permanente | Filtro duro + moderação |
| Unlike | -1.0 | sim | sim | não | retroativo | Subtrai o like original |

Notas:
- **Multi-sinal no mesmo post**: se o usuário curtiu **e** comentou, os pesos **somam** (+1.0 + 2.5 = 3.5). Não é sanitizado pro máximo.
- **Unique-like permanece**: um usuário gera no máximo uma entry de `type=like` por post. `interaction_events` de tipos diferentes no mesmo post são permitidos.
- **Decay half-life diferente por tipo**: não é previsto na v1 (todos usam 30d LT / 6h ST), mas é candidato a tuning.
- **Decay negativo**: sinais negativos **não** decaem da mesma forma. Hide expira em 30d; report é permanente; skip só influencia enquanto é recorrente.

---

## 7. Decisões técnicas principais

### Por que 1536 dimensões
Já é a escolha do projeto (`GeminiEmbeddingService` fixa `output_dimensionality=1536`). A doc do Gemini Embedding 2 permite 3072, 1536 ou 768 via MRL (Matryoshka). 1536 é o sweet spot: ~50% da qualidade do 3072 em benchmarks com 50% do custo de storage e ~2x menos RAM no HNSW. Para um workshop e escala baixa, mais que suficiente.

### Por que pgvector e não Qdrant/Pinecone/Weaviate
- Já em uso no projeto. Mudar de vetor store para a tese do workshop seria ruído.
- Volume esperado: O(10⁵) posts. HNSW em Postgres dá latência <50ms tranquilamente nesse range.
- **Trade-off real**: pgvector escala vertical (mesmo Postgres da app) e não tem filtragem pré-ANN tão flexível. Para O(10⁷)+ posts com muitas filters, Qdrant ganha. Não é nosso caso.

### Por que two-stage (candidate gen + ranking)
- **Custo**: aplicar score composto + MMR + filtros em 1M de posts é inviável. Em 1k é trivial (<20ms in-memory).
- **Modularidade**: cada fonte de candidato pode ter lógica diferente (ANN é contínuo, trending é discreto, following é booleano).
- **Tunability**: os mixes de fonte e os pesos do ranker são ajustáveis **sem** mexer no recall. É onde o operador vai iterar.

### Por que multi-vetor e não um único centroide
O estado atual (`users.embedding` = média simples) tem dois problemas:
1. **Temporalidade**: se o usuário virou fã de culinária essa semana mas ano passado só via futebol, o centroide carrega muito peso de futebol e a recomendação não se adapta.
2. **Disjunção**: média de dois interesses distintos (futebol + culinária) aterrissa num ponto do espaço que **não é parecido com nenhum dos dois** — é um ponto morto.
Separar long/short resolve (1); k-means em interesses resolve (2) — esse último entra como roadmap porque adiciona complexidade de escolha de K e estabilidade de clusters.

### Por que avoid como filtro e não como subtração
Matematicamente, `v_final = v_interest - v_avoid` produz um vetor com norma pequena e direção potencialmente irrelevante se os dois vetores forem parecidos (ex.: gosto de "culinária saudável" e odeio "culinária fast food" — os vetores são próximos; a subtração fica minúscula). Filtro por distância `cos(post, avoid) > θ` tem comportamento previsível: descarta posts que se parecem com o que o usuário detestou, sem afetar o resto do ranking.

### Por que Redis para short-term
- **Latência**: short-term é atualizado a cada like. Com Postgres, cada like faz `UPDATE users SET embedding = ...` que invalida cache de página e compete com writes do feed. Com Redis, é `SET` em memória, O(1).
- **Snapshot no Postgres**: mantém-se uma coluna `users.short_term_embedding` como snapshot (gravado a cada N atualizações ou no logout) — permite recuperar se o Redis cair.

### Por que Horizon (ou alternativa)
Jobs envolvidos: `GeneratePostEmbeddingJob` (lento, I/O-bound no Gemini, ~1-3s), `UpdateShortTermEmbeddingJob` (rápido, alta frequência), `RefreshLongTermEmbeddingsJob` (batch diário, pesado), `RefreshInterestClustersJob` (diário, pesado), `ComputeTrendingPoolJob` (frequente, médio).
Quatro filas com perfis diferentes — **`embeddings`**, **`realtime`**, **`batch`**, **`ingest`** — precisam de monitoramento. Sem Horizon, debugar fila travada vira `SELECT * FROM failed_jobs` à mão. Horizon também dá throttling por fila, que é importante pra não estourar rate limit do Gemini.

### Por que task_type correto importa
O Gemini Embedding 2 produz vetores **otimizados** para a task escolhida:
- `RETRIEVAL_DOCUMENT` — indexação de conteúdo. **Usar para embeddings de post.** (Já é o default do `GeminiEmbeddingService`.)
- `RETRIEVAL_QUERY` — consulta. **Usar quando embedar um texto de busca do usuário**. No cenário atual não fazemos busca por texto, mas se for implementar.
- `CLUSTERING` — otimizado pra k-means. **Usar no RefreshInterestClustersJob quando re-embedar posts para clusterização** (ou reutilizar o `RETRIEVAL_DOCUMENT` por simplicidade — piora marginal).
- Para o **user vector** não há task_type específico: ele é agregado (soma ponderada) de vetores `RETRIEVAL_DOCUMENT`. Funciona porque os vetores estão no mesmo espaço.

---

## 8. Trade-offs conhecidos

**Cold start de usuário novo é fraco.** Trending + diversidade é genérico. Sem sinal explícito de interesse (seletor de onboarding) ou sem integração com sinais externos, o sistema vai levar dezenas de interações para ranquear com qualidade.

**Sinais implícitos são ruidosos.** Dwell time mede "quanto tempo o post ficou na tela", não "quão interessante foi". Usuário deixa o celular na mesa; dwell vai pro céu; peso positivo é injustificado. Mitigação: cap em 30s, decay ST rápido, dwell afeta LT com peso reduzido (0.3×). Mesmo assim, é ruído.

**Skip de <1s também é ruidoso.** Scroll rápido pra chegar num post específico marca todos os intermediários como skip. Mitigação: só conta skip se houve 3+ posts depois *também scrollados* (inferência de "estava escaneando"). Ainda assim, falso positivo existe.

**Filter bubble.** Um sistema que otimiza por similaridade com o passado do usuário tende a colapsar o feed num nicho estreito. Mitigações: MMR (λ=0.7 já é agressivo), 10% de trending, 1% de random baseline, exploração de posts novos (10% do pool). Isso **reduz** mas não elimina. Um sistema de recomendação honesto precisa aceitar que exploração custa relevância de curto prazo.

**Popularity bias.** Trending é self-reinforcing: posts populares recebem mais views, logo ficam mais populares. Mitigação: trending pool é só 10% do feed, e o random baseline gera sinais não-enviesados que podem reequilibrar.

**Custo de embedding.** Cada post novo custa 1 chamada ao Gemini. Para 1250 posts do seed isso é barato. Para um produto com N posts/s, é preciso pensar em batching, cache por `hash(body + media_ids)`, e rate limit. Fora do escopo do workshop.

**Staleness entre short-term Postgres e Redis.** Se o Redis cair, o Postgres tem o último snapshot, mas pode ser desatualizado. O cold restart reconstrói o short-term dos últimos 48h de `interaction_events` num job `RebuildShortTermFromEventsJob`. Custo: um write inicial caro por usuário ativo.

**pgvector não filtra bem antes do ANN.** Queries como "ANN entre posts dos últimos 7 dias que não são do autor X e que eu não vi" funcionam, mas o HNSW pode não retornar os top-K reais se o filtro eliminar demais no pós-processamento. Mitigação: aumentar `ef_search` e o `LIMIT` do Stage 1 (buscar 500 em vez de 100 para ter margem após filtros).

**Ranking é síncrono na request do feed.** Com pool de 1k, é feasible in-memory em PHP (<50ms). Acima disso, precisa cache ou pre-compute por usuário.

---

## 9. Métricas de sucesso

Dividas em três grupos: **engajamento** (o sistema recomenda coisas que o usuário consome), **diversidade/saúde** (o sistema não colapsa), **técnicas** (o sistema está saudável operacionalmente).

### Engajamento
- **CTR por posição**: cliques (like/comment/share) / impressões, por posição no feed. A curva deve ser decrescente e estável; degradação súbita em posições top indica regressão.
- **Dwell time médio por impressão**: em segundos. Baseline atual (chronological + similaridade média) versus pós-rollout.
- **Taxa de skip rápido (<1s)**: proporção de impressões abandonadas antes de 1s. Métrica "de dor" — queda = usuário está encontrando o que gosta mais cedo.
- **Retenção D1/D7/D30**: clássica. Feed melhor → usuário volta.

### Diversidade / saúde
- **Gini de autores no feed**: concentração de autores servidos. Se 80% das impressões vão pra 5 autores, é ruim (filter bubble de criador).
- **Cobertura de catálogo**: posts únicos servidos / posts totais com embedding. Alvo: >60% do catálogo é servido a alguém em um período de 7 dias.
- **Novelty**: fração de impressões que são posts criados nas últimas 24h. Não pode cair abaixo de ~10% (nosso boost de exploration).
- **Intra-list diversity**: similaridade média entre pares de posts num mesmo feed servido. Deve **diminuir** após o rollout de MMR.

### Técnicas
- **p50 / p95 / p99 de latência do FeedService**. Alvo: p95 <200ms.
- **Throughput de `GeneratePostEmbeddingJob`**: posts/minuto. Alerta se backlog cresce.
- **Failure rate do Gemini**: alerta em >1%.
- **Tamanho do `interaction_events`**: crescimento por dia. Define política de retenção.
- **Concordância entre random baseline e ranker**: se o CTR do ranker em posições top não for significativamente melhor que random, o ranker não está agregando valor.

Instrumentação mínima:
- `ranking_logs` alimenta todas as métricas acima via query batch (job horário).
- Dashboards `[DECISÃO PENDENTE: Grafana? Telescope? Simples view Livewire dentro do próprio projeto?]`.

---

## 10. Glossário

- **Embedding**: vetor de N dimensões (aqui, 1536) que codifica o conteúdo (texto/imagem/vídeo) num espaço onde "coisas parecidas ficam perto". Gerado pelo Gemini no nosso caso.
- **Similaridade cosseno**: métrica de parecença entre dois vetores; 1.0 = idênticos em direção, 0 = ortogonais, -1 = opostos. pgvector usa `<=>` como **distância** cosseno (= 1 - similaridade); valores menores = mais parecido.
- **pgvector**: extensão do PostgreSQL que adiciona tipo `vector` e operadores `<=>`, `<->`, `<#>`. Já ativada no projeto.
- **HNSW**: Hierarchical Navigable Small World. Estrutura de índice para ANN (Approximate Nearest Neighbor) — encontra top-K vizinhos próximos num vetor em tempo logarítmico, com pequeno erro tolerável. Já em uso no índice `embedding_hnsw_idx`.
- **ANN (Approximate Nearest Neighbor)**: busca de vizinhos mais próximos aproximada. "Aproximada" = troca precisão por velocidade; vale a pena quando há milhões de vetores.
- **Candidate generation / Retrieval**: Stage 1 do pipeline. Gera um pool de ~1000 candidatos potencialmente relevantes, sem ranquear entre si.
- **Ranking**: Stage 2 do pipeline. Reordena os candidatos do Stage 1 com um score composto que leva em conta features caras por par usuário-post.
- **MMR (Maximal Marginal Relevance)**: algoritmo de reordenação que equilibra relevância e diversidade, evitando resultados redundantes.
- **Centroide**: "ponto médio" de um conjunto de vetores. O `users.embedding` atual é um centroide simples; o long-term proposto é um centroide ponderado com decay.
- **Decay exponencial (half-life)**: função de peso temporal. Half-life H: evento com idade H tem peso 0.5; idade 2H tem peso 0.25; etc. Fórmula: `w = 2^(-age / H)`.
- **Cold start**: situação em que não há dados suficientes (usuário novo, post novo) para recomendar/ser recomendado. Resolvido com heurísticas (trending, onboarding, exploration boost).
- **Filter bubble / Echo chamber**: quando o sistema só mostra ao usuário conteúdo parecido com o que ele já consumiu, reforçando viés. Mitigação: diversidade + random baseline.
- **Task type (Gemini)**: hint passado ao modelo de embedding indicando como o vetor será usado (`RETRIEVAL_DOCUMENT`, `RETRIEVAL_QUERY`, `CLUSTERING`, etc.). Afeta a geometria do espaço resultante.
- **Multi-vetor / multi-interest**: representar o usuário por vários vetores em vez de um centroide único. Capta interesses disjuntos.
- **Feedback loop**: sinais de engajamento do usuário voltam para o sistema e influenciam futuras recomendações.
- **Ranking log**: registro por candidato servido, com breakdown de scores. Base para análises offline e debug.

---

## 11. Perguntas em aberto (decisões pendentes)

Listagem consolidada dos `[DECISÃO PENDENTE: ...]` distribuídos pelo documento, mais algumas que surgem implicitamente:

1. **Queue / Horizon**: mover `QUEUE_CONNECTION` de `database` para `redis` e instalar `laravel/horizon`, ou manter DB queue e monitorar sem Horizon? (Seção 3)
2. **laravel/ai vs HTTP direto**: o `composer.json` já tem `laravel/ai` mas `GeminiEmbeddingService` usa `Http::post`. Refatorar ou manter? (Seção 3)
3. **Tabela `follows`**: incluir seguidores na v1 para alimentar a "following pool" do candidate gen, ou deixar como feature futura e rodar sem essa fonte? (Seções 3, 5.5)
4. **Locality pool**: não há coluna geográfica em nenhuma tabela. Dropar da v1, ou adicionar `users.timezone` / `posts.place_id` no escopo? (Seções 3, 5.5)
5. **Tabela `shares`**: criar entidade dedicada ou modelar share só como `interaction_events` row sem persistência de domínio? (Seção 5.2)
6. **Report / moderação**: o projeto não tem modelo de moderação. Para o workshop, basta hide duro + log pro instrutor, ou construir fluxo mínimo de moderação? (Seção 5.3)
7. **Onboarding com seletor de interesses**: implementar UI de bootstrap de long-term para novos usuários, ou aceitar cold start fraco? (Seção 5.7)
8. **Contexto para boost**: que features contextuais capturar além de hora do dia (timezone do user, device, referrer)? (Seção 5.5)
9. **Dashboards**: Grafana? Laravel Telescope / Horizon Dashboard? View Livewire dedicada dentro do app para o instrutor apresentar métricas ao vivo? (Seção 9)
10. **Retenção de `interaction_events`**: em volume alto, a tabela cresce linearmente. Particionar por mês? Purgar >180d? (Seção 6, implícito)
11. **Snapshot de short-term**: gravar em `users.short_term_embedding` a cada N interações ou no logout? Qual o N? (Seção 5.6)
12. **Substituir ou manter `CalculateCentroidJob` / `users.embedding`**: manter a coluna antiga durante migração e só remover depois que `long_term_embedding` estiver estável, ou corte direto? (Seção 5.2)
13. **Backfill de `interaction_events`**: o projeto já tem 300 usuários e likes no seed. Seedar `interaction_events` a partir dos likes atuais (com timestamps sintéticos) para ter dados de demonstração, ou começar do zero? (Seção 6)
14. **`θ_avoid` inicial**: qual limiar? Proposto 0.85, mas é chute — precisa de dados para calibrar. (Seção 4)
15. **k-means clustering library**: usar PHP puro (lento), Python via queue (microsserviço), ou adiar para fase 2 do workshop? (Seção 4)
