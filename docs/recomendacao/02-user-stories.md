# Sistema de Recomendação — Histórias de Usuário

Este documento traduz o desenho de `01-overview.md` em histórias acionáveis para implementação. Cada história é independente, tem critérios de aceitação verificáveis e aponta para o workflow do overview que endereça.

## Personas

- **Consumidor** — usuário autenticado rolando o feed. É o `App\Models\User` na prática; não há distinção de papel no domínio.
- **Criador** — o mesmo `User`, na hora em que publica. Separado aqui porque as necessidades são diferentes (discoverability, fairness).
- **Operador** — pessoa técnica (instrutor do workshop, SRE em produção) que precisa entender, ajustar e operar o sistema. Não é um papel no domínio; acessa via Artisan, comandos, logs, endpoints de debug.
- **Sistema** — jobs assíncronos, cron, reações a eventos. Não é uma pessoa; histórias aqui descrevem comportamento automatizado.

## Convenções

- MoSCoW: **Must** = sem isso o sistema não funciona; **Should** = importante, primeiro ciclo depois do MVP; **Could** = melhoria; **Won't** = fora do escopo deste workshop (listado para tornar a linha explícita).
- "Workflow relacionado" aponta para a numeração da seção em `01-overview.md`.
- Siglas recorrentes: LT = long-term embedding, ST = short-term embedding, AV = avoid embedding.

---

# Consumidor

## US-001 — Receber feed personalizado ao abrir o app

**Persona:** Consumidor
**Prioridade:** Must
**Workflow relacionado:** 5.5

**Como** consumidor com histórico de interações,
**Quero** que o feed seja ordenado pelo meu interesse em vez de apenas cronológico,
**Para que** eu veja conteúdo relevante sem precisar filtrar manualmente.

### Critérios de aceitação
- [ ] Ao acessar `GET /`, o componente `pages::feed.index` retorna posts ordenados pelo score composto (LT + ST + avoid + boosts), não por `created_at`.
- [ ] O feed retorna pelo menos 10 posts na primeira página (ou menos, se o catálogo tiver menos).
- [ ] P95 de latência de renderização inicial ≤ 250ms.
- [ ] Posts sem `post_embeddings` associado não aparecem (comportamento atual preservado).
- [ ] O mesmo usuário, acessando o feed duas vezes seguidas sem interagir, vê o mesmo ordenamento (determinismo no intervalo curto).

### Notas técnicas
- Substitui o atual `ORDER BY embedding <=> ?::vector` em `App\Livewire\Pages\Feed\Index::render()` por chamada a `RecommendationService::feedFor(User $user, int $page, int $pageSize)`.
- O service orquestra candidate generation (§5.5.a) + ranking (§5.5.c) + filtros + MMR.
- Usuários sem LT/ST caem no path de cold-start — ver US-005.

### Dependências
- US-020 (feature flags de peso), US-027 (LT batch), US-028 (ST realtime) para ter os vetores disponíveis.

### Métricas de sucesso
- CTR do feed (+20% vs. cronológico puro do baseline).
- Dwell time mediano ≥ 4s por post.

---

## US-002 — Feed reage em segundos a interações da sessão

**Persona:** Consumidor
**Prioridade:** Must
**Workflow relacionado:** 5.2, 5.6

**Como** consumidor que acabou de curtir/comentar posts sobre um tópico,
**Quero** que o feed traga mais conteúdo parecido já na próxima rolagem,
**Para que** a experiência acompanhe minha intenção naquele momento.

### Critérios de aceitação
- [ ] Após uma interação positiva (like, comment, share), a próxima requisição de feed (`loadMore()` ou refresh) reflete o novo ST dentro de ≤ 10 segundos no P95.
- [ ] O ST é atualizado via job na fila `realtime`, com debounce de 5s por usuário.
- [ ] O ST é lido de Redis no hot-path do feed (fallback para Postgres em caso de miss).
- [ ] O LT **não** é recalculado por essa interação — ele muda só no batch diário.

### Notas técnicas
- Event source: insert em `interactions` dispara `InteractionObserver`, que:
  1. Dá append em `rec:user:{id}:short_term` (lista circular Redis).
  2. Dispatch `RefreshShortTermEmbeddingJob` com lock `rec:user:{id}:st_lock` (TTL 5s).
- Janela do ST: últimas 48h, half-life 6h.
- Decisão pendente do overview: estratégia de dual-write em `likes` + `interactions` (ver §5.2 do overview).

### Dependências
- US-026 (tabela `interactions`), US-028 (`RefreshShortTermEmbeddingJob`), US-031 (Redis como cache de vetor quente).

### Métricas de sucesso
- Lag P95 da fila `realtime` < 10s.
- Intra-session CTR crescente ao longo da sessão.

---

## US-003 — Esconder posts indesejados e ver o sistema aprender

**Persona:** Consumidor
**Prioridade:** Must
**Workflow relacionado:** 5.3

**Como** consumidor que viu um post que não me interessa,
**Quero** marcar "não quero ver isso" e deixar de receber conteúdo similar,
**Para que** meu feed reflita o que eu realmente quero ver.

### Critérios de aceitação
- [ ] Existe ação "Esconder" (`hide`) visível em cada card do feed.
- [ ] Clicar em "Esconder" remove o post da viewport imediatamente e grava `interactions(kind='hide', weight=-1.5)`.
- [ ] O post escondido não volta a aparecer para esse usuário (entra no filtro "já visto"/oculto indefinidamente).
- [ ] O AV é atualizado em batch diário, incorporando todos os hides dos últimos 90d.
- [ ] Dentro de uma mesma sessão, posts **muito similares** ao escondido (cosseno > 0.85 com o escondido) têm score penalizado via AV assim que o AV é atualizado.

### Notas técnicas
- `hide` é sinal negativo explícito — entra no AV, não zera o LT/ST.
- Penalidade no ranking: `score -= β · cos(p, AV)`. β calibrável (decisão pendente no overview §7/§8).
- Filtro duro: guardar em `rec:user:{id}:hidden` (Redis set persistente, sem TTL).

### Dependências
- US-026 (tabela `interactions`), US-029 (AV batch job).

### Métricas de sucesso
- Hide rate cai na semana seguinte ao primeiro hide (indicador de que o sistema aprendeu).
- Hide rate global < 1% de impressões.

---

## US-004 — Reportar conteúdo impróprio

**Persona:** Consumidor
**Prioridade:** Should
**Workflow relacionado:** 5.3

**Como** consumidor que viu um post que viola regras,
**Quero** reportá-lo,
**Para que** ele saia do meu feed e seja avaliado para remoção do feed de outras pessoas.

### Critérios de aceitação
- [ ] Ação "Reportar" disponível em cada card, com modal para escolher motivo.
- [ ] Clicar grava `reports(user_id, post_id, reason)` e `interactions(kind='report', weight=-3.0)`.
- [ ] O post desaparece imediatamente do feed do reporter.
- [ ] Após N reports independentes (threshold a calibrar — decisão pendente §8.10 do overview), o post sai do pool global para **todos** os usuários.
- [ ] O report contribui com peso extra no AV (3.0) e persiste por 90 dias.

### Notas técnicas
- Tabela nova `reports(id, user_id, post_id, reason, created_at)`.
- `posts.reports_count` denormalizado para filtro rápido no candidate generation.
- Filtro duro no Stage 1: `WHERE reports_count < threshold`.

### Dependências
- US-026 (interactions), US-029 (AV batch job).

### Métricas de sucesso
- Report rate global < 0.1% de impressões.
- Tempo médio entre o N-ésimo report e a retirada do post do pool < 1 min.

---

## US-005 — Experiência de primeira sessão aceitável (cold start)

**Persona:** Consumidor (novo ou sem histórico)
**Prioridade:** Must
**Workflow relacionado:** 5.7

**Como** consumidor recém-registrado ou sem interações recentes,
**Quero** receber conteúdo variado e popular já na primeira sessão,
**Para que** eu consiga começar a usar o app mesmo sem o sistema me conhecer.

### Critérios de aceitação
- [ ] Se `user.long_term_embedding IS NULL` **e** `interactions_count < 5`, o feed é composto por:
  - Posts do pool **trending** (top por engajamento normalizado das últimas 24h).
  - Intercalados com posts recentes (slot 1 a cada 5).
- [ ] O sistema **não** tenta calcular centroide com 1 ou 2 interações (evita ruído).
- [ ] A partir da 5ª interação positiva, o usuário é promovido ao path de recomendação, começando com `α = 0.3` (peso alto em ST).
- [ ] Mensagem não-bloqueante (opcional na UI) explicando "estamos aprendendo seu gosto" nas primeiras sessões.

### Notas técnicas
- Decisão em `RecommendationService::feedFor()`: branch explícito para cold-start antes do candidate generation.
- Trending pool: cache Redis com TTL 5min, recalculado por job `RefreshTrendingPoolJob`.

### Dependências
- US-030 (trending pool), US-028 (ST realtime para entrar em regime rápido).

### Métricas de sucesso
- D1 return (retorno no dia seguinte) ≥ 40% para usuários novos.
- Tempo médio até 5ª interação < 5min.

---

## US-006 — Receber conteúdo diverso (não bolha)

**Persona:** Consumidor
**Prioridade:** Must
**Workflow relacionado:** 5.5 (MMR + quota por criador)

**Como** consumidor com gostos múltiplos,
**Quero** que o feed varie entre meus interesses em vez de ficar preso em um só tópico,
**Para que** a experiência não vire câmara de eco.

### Critérios de aceitação
- [ ] O top-20 do feed não tem mais que N posts do mesmo autor (N=2 por padrão).
- [ ] Dois posts adjacentes no feed não têm similaridade cosseno ≥ 0.9 entre si (validação pós-MMR).
- [ ] Quando o usuário tem múltiplos clusters de interesse, o top-20 representa ≥ 70% dos clusters ativos.
- [ ] Em usuários monomaníacos (1 cluster só), a regra de clusters não gera erro nem alerta — apenas não se aplica.

### Notas técnicas
- MMR aplicado no top ~100 após ranking, λ=0.7.
- Quota por criador aplicada como passo final (post-MMR).
- Clusters: `user_interest_clusters` com `cluster_index`, `centroid`, `weight`.

### Dependências
- US-033 (k-means de clusters), US-001 (feed pipeline).

### Métricas de sucesso
- Gini coefficient de autores no feed < 0.5.
- Cluster coverage no top-20 ≥ 70%.

---

## US-007 — Descobrir novos criadores e tópicos via exploração controlada

**Persona:** Consumidor
**Prioridade:** Should
**Workflow relacionado:** 5.5, 5.7 (exploration bonus)

**Como** consumidor que já tem um gosto estabelecido,
**Quero** que o feed ocasionalmente me mostre algo fora do meu padrão,
**Para que** eu descubra criadores/tópicos novos sem ter que sair do feed.

### Critérios de aceitação
- [ ] Pelo menos 1 post em cada janela de 10 posts vem de uma fonte de **exploração**: trending sem match semântico, ou post de autor que o usuário nunca viu.
- [ ] Posts recentes (≤ 6h) ganham um `recency_boost` decrescente (half-life 6h) no score.
- [ ] Posts de autores com < 3 impressões para esse usuário nos últimos 30 dias recebem um boost leve (creator discovery) — sujeito à decisão pendente §11.3 do overview.
- [ ] A exploração é visível nas métricas (fonte "explore" trackeada em `ranking_traces`).

### Notas técnicas
- Slot de exploração pode ser implementado como fonte separada no Stage 1 (`CandidateGenerator::exploration(user, 50)`) ou como regra de re-rank no Stage 2.
- Decisão pendente: boost explícito para "creator discovery" — ver §11.3 do overview.

### Dependências
- US-001 (pipeline), US-018 (observabilidade de fonte).

### Métricas de sucesso
- % de impressões em posts fora do top-10% mais populares ≥ 30%.
- % de usuários que interagem com ≥ 1 criador novo por semana ≥ 50%.

---

## US-008 — Ter o dwell time capturado de forma transparente

**Persona:** Consumidor
**Prioridade:** Must
**Workflow relacionado:** 5.4

**Como** consumidor,
**Quero** que o tempo que passo olhando cada post seja usado como sinal **sem que eu precise fazer nada extra**,
**Para que** o sistema aprenda com meu comportamento real, não só com cliques.

### Critérios de aceitação
- [ ] Frontend observa cada card via `IntersectionObserver` (threshold 50%) e mede tempo entre entrada e saída.
- [ ] Eventos são bufferizados no cliente e enviados em batch a cada 15s ou no `beforeunload` via `navigator.sendBeacon`.
- [ ] Backend grava em `interactions` com `kind='view'` e `weight` derivado da curva do overview §5.4:
  - `< 1000ms` → `skip_fast`, weight -0.3 (entra no AV)
  - `1000–3000ms` → neutro, **não** grava
  - `3000–10000ms` → weight 0.2–0.5 (linear)
  - `10000–30000ms` → weight 0.5–0.8
  - `> 30000ms` → weight 1.0 (cap)
- [ ] Nenhum dado identificável além de `user_id + post_id + dwell_ms` é coletado pela captura de view.
- [ ] Se o navegador bloqueia beacon ou JS, a ausência do sinal não quebra a renderização do feed.

### Notas técnicas
- Agregação: job `AggregateViewSignalsJob` roda a cada 10min por usuário com atividade — a gravação do evento individual é cheap (insert simples), mas agregação pesada fica fora do hot-path.
- Decisão pendente §11.2: curva exata validar com dados do workshop.

### Dependências
- US-026 (interactions), US-028 (ST para absorver views recentes).

### Métricas de sucesso
- Volume de eventos `view` ≥ 10x o volume de `like` (taxa esperada em feed scroll).
- Taxa de perda de eventos (beacon dropped) < 2%.

---

## US-009 — Entender por que um post está no meu feed

**Persona:** Consumidor (avançado / curioso)
**Prioridade:** Could
**Workflow relacionado:** 5.5 (ranking_traces)

**Como** consumidor,
**Quero** poder clicar em "por que vi isso?" em um post,
**Para que** eu entenda (e possa corrigir) as razões pelas quais o sistema o mostrou.

### Critérios de aceitação
- [ ] Menu "..." no card expõe ação "Por que vi isso?".
- [ ] A resposta é uma frase humanizada baseada nos dados de `ranking_traces` — ex.: *"Mostramos este post porque é parecido com fotos que você curtiu recentemente."*
- [ ] Nenhum detalhe técnico (score, vetor, cosseno) é exposto ao usuário final.
- [ ] Do lado de operador, os scores crus estão acessíveis via US-017.

### Notas técnicas
- Mapeamento fonte → frase:
  - `ann_short_term` → "parecido com o que você curtiu nas últimas horas"
  - `ann_long_term` → "parecido com seu gosto geral"
  - `trending` → "em alta agora"
  - `cluster_X` → "do grupo de interesses: [tópico inferido]"
  - `explore` → "algo novo para você descobrir"
- Lê `ranking_traces` pelo `(user_id, post_id, feed_request_id)`.

### Dependências
- US-017 (ranking_traces), US-001 (pipeline).

### Métricas de sucesso
- % de usuários que clicam "por que vi isso?" pelo menos 1x / semana ≥ 15%.

---

## US-010 — Ter meu gosto "desaprendido" quando eu desmarcar um like

**Persona:** Consumidor
**Prioridade:** Should
**Workflow relacionado:** 5.2, 5.3

**Como** consumidor que curtiu algo por engano,
**Quero** desmarcar o like e ver o sistema **reverter parcialmente** aquele sinal,
**Para que** um erro pontual não envenene meu feed por semanas.

### Critérios de aceitação
- [ ] Ao remover um like, uma nova `interaction(kind='unlike', weight=-0.5)` é gravada (não apenas o `Like` row é deletado).
- [ ] No próximo recompute de ST/LT, a contribuição do post é reduzida (não zerada — o usuário curtiu e mudou de ideia, ambas são informação).
- [ ] O `LikeObserver` existente continua disparando `CalculateUserCentroidJob` (compatibilidade temporária) até a migração para `RefreshShortTermEmbeddingJob` ser feita.

### Notas técnicas
- Hoje `LikeObserver::deleted` já dispara recompute — mas o recompute é simplesmente re-média sobre os likes restantes, sem memória do unlike. Com `interactions`, ganhamos o sinal explícito.

### Dependências
- US-026 (interactions), US-027 (LT), US-028 (ST).

### Métricas de sucesso
- Satisfação subjetiva (se houver pesquisa) — fora do escopo do workshop.

---

# Criador

## US-011 — Ter meu post indexado em embedding logo após publicação

**Persona:** Criador
**Prioridade:** Must
**Workflow relacionado:** 5.1

**Como** criador que acabou de publicar um post,
**Quero** que ele esteja elegível para recomendação em segundos,
**Para que** eu não perca a janela inicial de engajamento.

### Critérios de aceitação
- [ ] Ao criar um post (`PostObserver::created`), `GeneratePostEmbeddingJob` é dispatched na fila `embeddings`.
- [ ] O job roda em ≤ 30s no P95 (texto + até 10 imagens).
- [ ] Falhas do job são retry com backoff exponencial (base 5s, máx. 3 tentativas); após isso, job vai para `failed_jobs` e gera alerta (ver US-019).
- [ ] O post aparece no candidate pool assim que `post_embeddings` tem a linha gravada.
- [ ] O dispatch **deixa de ser síncrono** (`dispatch_sync` atual em `PostObserver::created`) — passa para dispatch assíncrono.

### Notas técnicas
- Regressão a cuidar: hoje `dispatch_sync` garante que testes Pest vejam o embedding imediatamente. Após migração para async, ajustar testes com `Queue::fake()` + assert do dispatch, ou rodar `Queue::assertPushed`/sync explicitamente em contexto de teste.
- Em caso de post sem texto **e** sem mídia (não deve acontecer pelas regras atuais), o job pula silenciosamente.

### Dependências
- US-025 (Horizon + filas nomeadas).

### Métricas de sucesso
- P95 do tempo entre `Post::create()` e `post_embeddings` existir ≤ 30s.
- Taxa de erro do job < 0.5%.

---

## US-012 — Não ser penalizado por publicar em horário de baixa audiência

**Persona:** Criador
**Prioridade:** Should
**Workflow relacionado:** 5.5 (recency_boost), 5.7

**Como** criador que publica às 3 da manhã,
**Quero** que meu post ainda tenha chance justa de ser descoberto quando o pico de audiência chegar,
**Para que** o algoritmo não me puna por horário de publicação.

### Critérios de aceitação
- [ ] O ranking aplica `recency_boost` com half-life de 6h — posts de 6h atrás ainda têm metade do boost, posts de 12h têm 1/4.
- [ ] Posts sem interação ainda **entram** no candidate pool via ANN (não dependem de trending para existir).
- [ ] Métrica por criador: tempo médio até a primeira impressão ≤ 1h, independente de horário de publicação.

### Notas técnicas
- `recency_boost = γ · exp(-ln(2) · age_hours / 6)`.
- γ calibrável via config (ver US-020).

### Dependências
- US-001 (pipeline), US-020 (feature flags de peso).

### Métricas de sucesso
- Distribuição de impressões por hora-de-publicação: desvio padrão < 20% da média.

---

## US-013 — Ter cota justa de aparição (fairness entre criadores)

**Persona:** Criador
**Prioridade:** Must
**Workflow relacionado:** 5.5 (quota por criador)

**Como** criador em uma plataforma com criadores prolíficos,
**Quero** que nenhum único autor monopolize o feed,
**Para que** eu tenha oportunidade de ser descoberto mesmo publicando menos.

### Critérios de aceitação
- [ ] No top-K do feed (K=20 por padrão), no máximo N posts (N=2) do mesmo `author_id` são permitidos.
- [ ] A regra é aplicada como passo pós-MMR, não como filtro no Stage 1 (para não excluir do pool).
- [ ] Se a aplicação da regra deixar o top-K com menos de K posts, o sistema completa com o próximo melhor candidato respeitando a quota.

### Notas técnicas
- Implementar em `Ranker::applyAuthorQuota(array $ranked, int $k, int $perAuthor)`.
- N e K configuráveis.

### Dependências
- US-001 (pipeline).

### Métricas de sucesso
- Gini coefficient de autores no feed < 0.5.
- % de criadores com ≥ 1 impressão / semana ≥ 80%.

---

## US-014 — Ver o post ser reindexado se a legenda for editada

**Persona:** Criador
**Prioridade:** Should
**Workflow relacionado:** 5.1 (mesmo workflow, aplicado a update)

**Como** criador que corrigiu a legenda do post,
**Quero** que o embedding seja recalculado para refletir o novo texto,
**Para que** a recomendação use a informação atual.

### Critérios de aceitação
- [ ] `PostObserver::updated` detecta mudança em `body` e dispara `GeneratePostEmbeddingJob` com flag `replace=true`.
- [ ] O job cria uma **nova** linha em `post_embeddings` e marca as antigas como superseded (ou deleta — ver decisão pendente §11.8 do overview).
- [ ] Edição apenas de outros campos (que não `body`) não dispara re-embedding.
- [ ] Não há re-embedding para posts tipo `image/video` apenas por edição de caption? — **Sim, há**: caption é parte do `parts` enviado ao Gemini, então muda o embedding.

### Notas técnicas
- Decisão pendente §11.8: manter histórico de embeddings ou substituir? Recomendação: substituir para simplicidade; histórico só se houver demanda de auditoria.

### Dependências
- US-011.

### Métricas de sucesso
- P95 do re-embed após update ≤ 30s.

---

## US-015 — Ver meu post retirado do feed global se for reportado demais

**Persona:** Criador (tanto quem criou quanto a comunidade)
**Prioridade:** Should
**Workflow relacionado:** 5.3, 3

**Como** criador,
**Quero** regras claras sobre quando um post meu é retirado por reports,
**Para que** eu saiba o que esperar da moderação automatizada.

### Critérios de aceitação
- [ ] Um post é retirado do pool global de recomendação após N reports independentes (threshold configurável — decisão pendente §11.10 do overview).
- [ ] O criador **não** recebe notificação automática neste MVP (fora de escopo do workshop).
- [ ] O post permanece na timeline do próprio autor (não é deletado; apenas filtrado no candidate generation).
- [ ] Um operador pode reverter a retirada via comando Artisan (ver US-022).

### Notas técnicas
- Filtro no Stage 1: `WHERE posts.reports_count < threshold`.
- Threshold inicial: 5 reports independentes.

### Dependências
- US-004, US-022 (operador reverter).

### Métricas de sucesso
- Falsos positivos (posts retirados que deveriam estar ativos) < 1% após revisão mensal.

---

# Operador

## US-016 — Debugar por que um post foi (ou não foi) recomendado

**Persona:** Operador
**Prioridade:** Must
**Workflow relacionado:** 5.5 (ranking_traces)

**Como** operador investigando uma reclamação do tipo "por que esse post apareceu?",
**Quero** ver o rastro completo de ranking para um `(user_id, post_id)` específico,
**Para que** eu consiga diagnosticar o comportamento sem adivinhar.

### Critérios de aceitação
- [ ] Comando `./vendor/bin/sail artisan rec:trace {user_id} {post_id} [--request=ID]` retorna:
  - Fonte do candidato (ann_lt, ann_st, trending, cluster_X, explore, following).
  - Scores parciais: `sim_lt`, `sim_st`, `sim_avoid`, `recency_boost`, `trending_boost`, `context_boost`, `final_score`.
  - Posição final no feed.
  - Flags de filtro (passou por "já visto"? quota? MMR?).
- [ ] Se o post **não** foi recomendado, o comando indica em qual etapa foi excluído (ex.: "filtrado em Stage 1 por `reports_count > threshold`").
- [ ] `ranking_traces` tem TTL 7 dias — consultas fora da janela retornam "trace expirado".

### Notas técnicas
- Tabela `ranking_traces(id, feed_request_id, user_id, post_id, source, scores jsonb, final_position, filtered_reason, created_at)`.
- Insert assíncrono na fila `traces` para não impactar hot-path.

### Dependências
- US-001 (pipeline que emite traces), US-017 (persistência).

### Métricas de sucesso
- Tempo médio de diagnóstico de uma reclamação de ranking < 10min.

---

## US-017 — Ter rastros de ranking persistidos por 7 dias

**Persona:** Operador / Sistema
**Prioridade:** Must
**Workflow relacionado:** 5.5

**Como** sistema,
**Quero** persistir o traço de cada decisão de ranking por 7 dias,
**Para que** investigações e análises offline tenham dados concretos.

### Critérios de aceitação
- [ ] Toda renderização do feed emite N inserts em `ranking_traces` (N = posts no feed dessa requisição).
- [ ] Inserts são feitos via job assíncrono (`PersistRankingTracesJob` na fila `traces`) — não bloqueiam a resposta.
- [ ] Traces com `created_at < now() - 7 days` são removidos via job noturno (`PurgeRankingTracesJob`).
- [ ] Volume esperado: ~10 traces/feed × ~1000 feeds/dia = ~10k/dia (seed do workshop) — tabela particionada por dia se for pra escalar.

### Notas técnicas
- Schema proposto: `ranking_traces(id bigint, feed_request_id uuid, user_id fk, post_id fk, source text, scores jsonb, final_position int, filtered_reason text null, created_at timestamp)`.
- Index em `(user_id, post_id, created_at)` para consulta do US-016.

### Dependências
- US-025 (Horizon).

### Métricas de sucesso
- 100% das renderizações de feed geram trace.
- Tempo de retenção efetivo = 7 dias ± 1h.

---

## US-018 — Monitorar métricas de qualidade do sistema em dashboard

**Persona:** Operador
**Prioridade:** Should
**Workflow relacionado:** 9 (métricas do overview)

**Como** operador,
**Quero** um dashboard com as métricas-chave do sistema de recomendação,
**Para que** eu detecte degradações antes dos usuários.

### Critérios de aceitação
- [ ] Métricas expostas em endpoint `/admin/rec/metrics` (ou Prometheus scrape endpoint):
  - **Engajamento**: CTR, dwell mediano, interactions/session.
  - **Diversidade**: Gini de autores, cluster coverage.
  - **Cobertura**: % do catálogo impressionado em 7d, % de cauda longa.
  - **Qualidade negativa**: hide rate, report rate.
  - **Sistema**: P50/P95 de latência do feed, lag das filas, taxa de erro de `GeneratePostEmbeddingJob`.
- [ ] Cada métrica com janela de 1h, 24h e 7d.
- [ ] Comparação grupo "recommendation" vs. "random serving 1%" (uplift).

### Notas técnicas
- Agregação pode rodar em materialized view no Postgres, atualizada a cada 5min, ou via Prometheus + Grafana.
- Para o escopo do workshop, uma página Livewire simples pode ser suficiente.

### Dependências
- US-017 (traces), US-024 (random serving).

### Métricas de sucesso
- Dashboard tem 100% das métricas de §9 do overview.
- MTTR (mean time to repair) de degradações < 1h após dashboard em uso.

---

## US-019 — Detectar falhas em jobs de embedding e ser alertado

**Persona:** Operador
**Prioridade:** Must
**Workflow relacionado:** 5.1, 5.6

**Como** operador,
**Quero** receber alerta quando jobs de embedding falharem acima de um limiar,
**Para que** eu detecte incidentes de API Gemini, rate limit, credenciais expiradas, etc.

### Critérios de aceitação
- [ ] Alerta dispara se a taxa de erro de `GeneratePostEmbeddingJob` > 5% em janela de 1h.
- [ ] Alerta dispara se `RefreshShortTermEmbeddingJob` tem lag P95 > 60s sustentado por 5min.
- [ ] Alerta dispara se o job `RefreshLongTermEmbeddingsJob` (diário) falhar completamente ou demorar > 2× o tempo médio.
- [ ] Canal de alerta: log estruturado + (opcional) Slack via `config('services.slack')` já existente.
- [ ] `failed_jobs` é verificada — se N jobs do mesmo tipo falham no mesmo dia, um único alerta agregado é emitido (não spam).

### Notas técnicas
- Horizon dashboard já dá visibilidade — alerta é ativo, além da observação passiva.
- Implementar via `Schedule` (Laravel) chamando um comando `rec:healthcheck`.

### Dependências
- US-025 (Horizon).

### Métricas de sucesso
- Falhas detectadas com lag < 5min.
- Zero incidentes de "ficou quebrado por 24h e ninguém viu".

---

## US-020 — Ajustar pesos de sinais sem deploy (feature flags / config)

**Persona:** Operador
**Prioridade:** Must
**Workflow relacionado:** 6, 7

**Como** operador calibrando o sistema,
**Quero** ajustar pesos (α, β, γ, δ, ε, half-lives, pesos por sinal) via configuração,
**Para que** eu possa iterar sem precisar redeploy.

### Critérios de aceitação
- [ ] Todos os pesos citados no overview §6 e §7 estão em `config/recommendation.php` (arquivo novo).
- [ ] Cada peso é lido do `config()` em tempo de execução, não em propriedade estática no `RecommendationService`.
- [ ] Opcional: override em runtime via tabela `recommendation_settings` (chave-valor, hot-reload via cache clear) para ajuste sem alterar código.
- [ ] Mudança de peso tem efeito na próxima requisição de feed (sem reiniciar workers — ou com reinício se cache persistente).

### Notas técnicas
- `config/recommendation.php` agrupa:
  - `weights` (like, comment, share, etc.)
  - `half_life` (lt, st, av por tipo)
  - `score` (alpha, beta, gamma, delta, epsilon)
  - `mmr` (lambda, pool_size)
  - `quota` (top_k, per_author)
  - `cold_start` (threshold)
- Se usar DB override: cache `recommendation_settings` em Redis com TTL 1min.

### Dependências
- US-001.

### Métricas de sucesso
- Tempo para mudar e validar um peso ≤ 5min.

---

## US-021 — Executar A/B test de mudanças de ranking

**Persona:** Operador
**Prioridade:** Could
**Workflow relacionado:** 9 (random serving já cobre baseline)

**Como** operador experimentando uma nova fórmula de score,
**Quero** servir a variante B para X% dos usuários e comparar métricas,
**Para que** eu decida com base em dados se a mudança vai para 100%.

### Critérios de aceitação
- [ ] Config `recommendation.experiments` define variantes (A = baseline, B = alternativa) com `user_hash % 100 < X`.
- [ ] `ranking_traces` registra a variante servida (`context.variant`).
- [ ] Dashboard (US-018) expõe métricas quebradas por variante.
- [ ] Término do experimento é manual (remover config entry).

### Notas técnicas
- Hash determinístico: `hash(user_id . experiment_name) % 100`.
- Começar simples: suportar 1 experimento por vez; múltiplos experimentos ortogonais é complicação desnecessária no workshop.

### Dependências
- US-018 (dashboard), US-017 (traces com variante).

### Métricas de sucesso
- Pelo menos 1 experimento rodado com resultados significativos durante o workshop.

---

## US-022 — Fazer backfill/re-embedding em massa

**Persona:** Operador
**Prioridade:** Must
**Workflow relacionado:** 5.1

**Como** operador após mudança de modelo, dimensão, ou bug que corrompeu embeddings,
**Quero** re-embeddar todos (ou um subconjunto) de posts em batch,
**Para que** eu recupere consistência sem criar cada post manualmente.

### Critérios de aceitação
- [ ] Comando `app:generate-post-embeddings` já existe — **preservar** e estender:
  - `--force`: regenerar mesmo se já existir (já existe).
  - `--since=DATE`: só posts criados após data.
  - `--post-ids=1,2,3`: subconjunto específico.
  - `--dispatch-queue`: em vez de `dispatch_sync`, enfileira em `embeddings`.
- [ ] Progress bar com ETA.
- [ ] Falhas por post são logadas mas não interrompem o batch (comportamento atual preservado).
- [ ] Comando complementar `app:reverse-report {post_id}` (US-015) para operador reverter retirada automática.

### Notas técnicas
- Hoje: `App\Console\Commands\GeneratePostEmbeddings` usa `dispatch_sync` — adicionar opção de queue para batches grandes.

### Dependências
- US-011.

### Métricas de sucesso
- Backfill de 1250 posts (seed) completa em < 30min (com queue).

---

## US-023 — Kill-switch de emergência para voltar ao feed cronológico

**Persona:** Operador
**Prioridade:** Must
**Workflow relacionado:** 5.5 (fallback global)

**Como** operador em incidente grave (API Gemini caiu, vetor corrompido, bug em produção),
**Quero** desligar o sistema de recomendação e voltar ao feed cronológico com 1 comando,
**Para que** o produto continue utilizável enquanto eu investigo.

### Critérios de aceitação
- [ ] Config `recommendation.enabled` (default `true`).
- [ ] Quando `false`, `RecommendationService::feedFor()` retorna resultado idêntico ao `latest('created_at')` atual.
- [ ] Mudança tem efeito em ≤ 1min (cache clear se necessário).
- [ ] Comando Artisan `rec:disable` / `rec:enable` muda o flag e limpa caches relevantes.
- [ ] Logado com razão obrigatória: `rec:disable --reason="gemini-api-outage"`.

### Notas técnicas
- Em cima do US-020; é essencialmente um flag global.

### Dependências
- US-020.

### Métricas de sucesso
- Tempo de desligamento em incidente < 1min.

---

## US-024 — Servir 1% do tráfego com random serving (baseline)

**Persona:** Operador / Sistema
**Prioridade:** Should
**Workflow relacionado:** 9

**Como** operador avaliando uplift do sistema,
**Quero** um grupo de controle não-enviesado,
**Para que** eu tenha referência para medir o ganho real.

### Critérios de aceitação
- [ ] 1% dos usuários (determinístico por `hash(user_id + day) % 100 < 1`) recebe feed cronológico puro (ou aleatório — decidir ao implementar).
- [ ] `ranking_traces.context.variant = 'control'` para esse grupo.
- [ ] Dashboard (US-018) mostra métricas do grupo de controle lado a lado com grupo de tratamento.
- [ ] A rotação é diária (mesmo usuário pode estar em controle um dia e em tratamento no outro) — evita bolsões de insatisfação.

### Notas técnicas
- Cuidado com correlação de cohort: se o hash for apenas `user_id % 100`, 1% estará sempre em controle — o que pode degradar retention para eles. Rotação por `user_id + day` mitiga.

### Dependências
- US-001, US-017, US-018.

### Métricas de sucesso
- Uplift estatisticamente significativo (p < 0.05) em CTR e dwell do grupo tratamento.

---

## US-025 — Ter filas nomeadas e Horizon operando

**Persona:** Operador
**Prioridade:** Must
**Workflow relacionado:** 5.1, 5.6, 7

**Como** operador,
**Quero** múltiplas filas nomeadas com prioridades distintas e visibilidade via Horizon,
**Para que** jobs críticos (short-term) não esperem atrás de jobs pesados (k-means).

### Critérios de aceitação
- [ ] Instalar `laravel/horizon`; substituir driver de queue `database` por `redis`.
- [ ] Filas criadas: `realtime`, `embeddings`, `clusters`, `longterm`, `traces`.
- [ ] Horizon balanceia workers por fila (mais workers em `realtime`, menos em `clusters`).
- [ ] Dashboard Horizon acessível (com auth de admin) em `/horizon`.
- [ ] Jobs rotulam `->onQueue('realtime')` etc. explicitamente.

### Notas técnicas
- Mudança de infra: adicionar Redis ao `compose.yaml` como serviço (hoje só o client está configurado).
- Config `queue.connections.redis`.
- Documentar no README o novo serviço.

### Dependências
- Infraestrutura Redis.

### Métricas de sucesso
- Lag da fila `realtime` P95 < 10s com carga do workshop.
- Horizon dashboard acessível.

---

# Sistema

## US-026 — Registrar todos os sinais de interação em uma tabela append-only

**Persona:** Sistema
**Prioridade:** Must
**Workflow relacionado:** 5.2, 5.3, 5.4, 6

**Como** sistema,
**Quero** persistir cada sinal de interação (explícito e implícito) em uma tabela única e imutável,
**Para que** toda agregação de vetor e análise offline parta de uma fonte de verdade.

### Critérios de aceitação
- [ ] Migração cria `interactions(id, user_id, post_id, kind, weight, occurred_at, context jsonb)`.
- [ ] `kind` é enum: `like`, `unlike`, `comment`, `share`, `view`, `skip_fast`, `hide`, `report`, `author_block`.
- [ ] Indices: `(user_id, occurred_at)`, `(post_id, occurred_at)`, `(kind, occurred_at)`.
- [ ] Tabela é append-only (sem UPDATE, sem DELETE — exceto purge anual via job).
- [ ] Migração dual-write ativa: `LikeObserver`, `CommentObserver` (novo) gravam em `likes`/`comments` **e** em `interactions` até as tabelas antigas deixarem de ser lidas como sinal.
- [ ] Decisão do overview §11.9: manter essa tabela única em vez de múltiplas tabelas por kind.

### Notas técnicas
- Volume esperado: na ordem de 100-1000 inserts/sec no pico do workshop — tabela particionada por mês se escalar.
- `context jsonb` registra: `device`, `hour_of_day`, `day_of_week`, `session_id`, `feed_source`, `feed_position`.

### Dependências
- Nenhuma.

### Métricas de sucesso
- 100% dos sinais registrados em `interactions` após rollout.
- Lag entre evento UI e insert ≤ 500ms.

---

## US-027 — Recalcular user long-term embedding em batch diário

**Persona:** Sistema
**Prioridade:** Must
**Workflow relacionado:** 5.6

**Como** sistema,
**Quero** recalcular o LT de cada usuário ativo diariamente,
**Para que** o gosto estável reflita os últimos 90–180 dias com decay apropriado.

### Critérios de aceitação
- [ ] Comando `app:refresh-long-term-embeddings` roda às 03:00 BRT via `Schedule`.
- [ ] Para cada `user` com atividade nos últimos 7 dias:
  - Carrega interações positivas dos últimos 180 dias.
  - Calcula `v = Σ w_eff_i · post_embedding_i / Σ w_eff_i`, com `w_eff = w_base · exp(-ln(2) · age_dias / 30)`.
  - Normaliza L2 e persiste em `users.long_term_embedding`.
- [ ] Usuários inativos há > 30 dias são pulados.
- [ ] Job emite métrica: usuários processados, tempo total, falhas.
- [ ] Falha total do job dispara alerta (US-019).
- [ ] O atual `CalculateUserCentroidJob` é deprecado após este job estar estável (ou adaptado para ser o novo job).

### Notas técnicas
- Substitui a lógica de média simples do `CalculateUserCentroidJob` atual.
- A coluna `users.embedding` atual vira `users.long_term_embedding` (migração rename + repurpose).

### Dependências
- US-026.

### Métricas de sucesso
- Batch completa < 30min para 300 usuários (seed).
- LT disponível para ≥ 95% dos usuários ativos.

---

## US-028 — Atualizar user short-term embedding em tempo real (com debounce)

**Persona:** Sistema
**Prioridade:** Must
**Workflow relacionado:** 5.2, 5.6

**Como** sistema,
**Quero** recalcular o ST de um usuário poucos segundos após cada interação,
**Para que** o feed reflita intenção recente.

### Critérios de aceitação
- [ ] `InteractionObserver::created` dispatcha `RefreshShortTermEmbeddingJob` na fila `realtime`.
- [ ] Lock Redis `rec:user:{id}:st_lock` (TTL 5s) evita recomputes concorrentes: se job já pendente, novo dispatch é descartado (debounce).
- [ ] Job carrega interações positivas das últimas 48h, aplica `w_eff = w_base · exp(-ln(2) · age_horas / 6)`, normaliza L2.
- [ ] Persiste em `users.short_term_embedding` (Postgres) **e** em `rec:user:{id}:short_term` (Redis, TTL 1h).
- [ ] Se `Σ w_eff < threshold` (ex.: 2.0), grava NULL e o feed cai para fallback.

### Notas técnicas
- Debounce é crítico: um usuário em scroll frenético pode gerar 10 interações/s — sem debounce, a fila trava.
- Alternativa: windowed dispatch (1 job a cada 5s independente de eventos). Mais simples, menos reativo.

### Dependências
- US-026, US-025 (Horizon/Redis), US-031 (Redis cache).

### Métricas de sucesso
- Lag P95 da fila `realtime` < 10s.
- ST atualizado em Redis disponível no hot-path do feed.

---

## US-029 — Recalcular avoid embedding em batch diário

**Persona:** Sistema
**Prioridade:** Should
**Workflow relacionado:** 5.3, 5.6

**Como** sistema,
**Quero** agregar interações negativas em um vetor AV atualizado diariamente,
**Para que** o ranking penalize conteúdo parecido com o que o usuário rejeitou.

### Critérios de aceitação
- [ ] Piggyback no job de LT (US-027): no mesmo loop, também calcula AV.
- [ ] Fonte: interações `hide`, `report`, `skip_fast` recorrente (mesmo autor N vezes) dos últimos 90 dias.
- [ ] Pesos e half-lives conforme tabela do overview §6.
- [ ] Persiste em `users.avoid_embedding` (vetor 1536, nullable).

### Notas técnicas
- AV pode ser NULL em usuários sem interação negativa — o ranking trata NULL como "sem penalidade" (cos contribution = 0).

### Dependências
- US-027.

### Métricas de sucesso
- AV disponível para 100% dos usuários com ≥ 1 hide/report.

---

## US-030 — Manter pool global de posts em trending

**Persona:** Sistema
**Prioridade:** Must
**Workflow relacionado:** 5.5 (candidate generation), 5.7 (cold start)

**Como** sistema,
**Quero** ter disponível a qualquer momento o pool de "posts em alta nas últimas 24h",
**Para que** cold-start e exploration possam usá-lo como fonte.

### Critérios de aceitação
- [ ] Job `RefreshTrendingPoolJob` roda a cada 5min.
- [ ] Critério: `trending_score = (Σ interactions.weight · decay_24h) / impressions_count` nas últimas 24h.
- [ ] Top 200 posts cacheados em Redis `rec:trending:global` (sorted set).
- [ ] Pool exclui posts com `reports_count > threshold`.

### Notas técnicas
- Normalização por impressions evita viés a favor de posts que já foram mostrados muito.
- Se quisermos "trending por tipo" (texto, imagem, vídeo), pool por tipo + union.

### Dependências
- US-026.

### Métricas de sucesso
- Refresh completa em < 30s.
- Cache sempre disponível (TTL 10min, refresh a cada 5min).

---

## US-031 — Invalidar cache de recomendações quando apropriado

**Persona:** Sistema
**Prioridade:** Should
**Workflow relacionado:** 5.5, 5.6

**Como** sistema,
**Quero** limpar caches relevantes ao detectar mudanças significativas,
**Para que** o feed reflita o estado mais recente sem esperar TTL.

### Critérios de aceitação
- [ ] Ao hide/report (sinal forte), invalidar `rec:user:{id}:candidates` (cache de candidatos, se existir — decisão pendente §11.7 do overview).
- [ ] Ao update de `users.short_term_embedding`, invalidar cache do feed do usuário (se houver).
- [ ] Ao retirada de post por reports (US-004), purgar `rec:trending:global` e forçar refresh.
- [ ] Invalidações **não bloqueiam** o fluxo principal (side-effect assíncrono).

### Notas técnicas
- Estratégia conservadora: TTL curto (1-5min) é mais seguro que invalidação precisa. A TTL + invalidação nos eventos fortes dão boa relação custo/benefício.

### Dependências
- US-025 (Redis).

### Métricas de sucesso
- Tempo P95 entre hide e próximo feed refletir a penalidade < 30s.

---

## US-032 — Degradar graciosamente se a API Gemini cair

**Persona:** Sistema
**Prioridade:** Must
**Workflow relacionado:** 5.1, 5.5

**Como** sistema,
**Quero** continuar operando quando a API de embedding ficar indisponível,
**Para que** o produto não pare completamente por uma dependência externa.

### Critérios de aceitação
- [ ] `GeneratePostEmbeddingJob` com timeout (30s), retry com backoff (3 tentativas), e circuit breaker:
  - Após N falhas consecutivas (ex.: 10) em 1min, para de dispatchar e deixa em fila.
  - Re-tenta automaticamente após M min.
- [ ] Posts sem embedding aparecem no feed apenas via **fallback cronológico** (não via ANN) — comportamento atual já faz isso implicitamente (JOIN filtra).
- [ ] Feed do usuário continua funcionando com os posts que já têm embedding — não é afetado pela indisponibilidade da API.
- [ ] Alerta dispara após 5min de falhas sustentadas (US-019).
- [ ] Opção manual: kill-switch (US-023) para reverter para cronológico global.

### Notas técnicas
- Circuit breaker pode ser implementado com contador em Redis — simples contador + TTL.
- `RefreshShortTermEmbeddingJob` **não** depende de API Gemini (só agrega vetores já existentes) — continua funcionando normalmente.

### Dependências
- US-019, US-023, US-025.

### Métricas de sucesso
- Durante outage simulado, 0% de erros 500 no feed do usuário.
- MTTR automático (sem intervenção) após retorno da API < 5min.

---

## US-033 — Manter clusters de interesse do usuário

**Persona:** Sistema
**Prioridade:** Could
**Workflow relacionado:** 4, 5.5

**Como** sistema,
**Quero** identificar múltiplos centros de interesse de cada usuário,
**Para que** o feed diversifique entre esses interesses em vez de colapsar em uma média.

### Critérios de aceitação
- [ ] Job `app:refresh-interest-clusters` roda semanalmente ou quando `|interactions_positivas| - |interactions_na_ultima_execucao| > 20`.
- [ ] Algoritmo: k-means (k=3..7 escolhido via silhouette score) sobre embeddings de posts com sinal positivo nos últimos 90 dias.
- [ ] Persiste em `user_interest_clusters(user_id, cluster_index, centroid vector(1536), weight, refreshed_at)`.
- [ ] Usuários com < 30 interações positivas **não** têm clusters (caem no path LT+ST padrão).
- [ ] `CandidateGenerator::annByClusters()` usa esses centroides como múltiplas fontes.

### Notas técnicas
- k-means via PHP puro é custoso com 1536 dimensões; considerar usar `pgvector` + `pgml` ou rodar em script Python offline. Para o workshop, PHP simples resolve.
- Weight do cluster = fração de interações pertencentes ao cluster.

### Dependências
- US-027 (dados de interação).

### Métricas de sucesso
- Cluster coverage no top-20 ≥ 70% (US-006).

---

## US-034 — Purgar eventos antigos após retenção

**Persona:** Sistema
**Prioridade:** Could
**Workflow relacionado:** -

**Como** sistema,
**Quero** remover dados antigos de tabelas volumosas,
**Para que** a base não cresça indefinidamente.

### Critérios de aceitação
- [ ] `interactions` anteriores a 1 ano são arquivadas ou deletadas (dependendo de política — no workshop, deletadas).
- [ ] `ranking_traces` já cobertas por US-017.
- [ ] Job `app:purge-old-events` roda semanalmente.

### Notas técnicas
- Em produção, LGPD obriga política mais rigorosa (direito ao esquecimento). Fora de escopo do workshop.

### Dependências
- US-026.

### Métricas de sucesso
- Tamanho de `interactions` estável em janela móvel de 12 meses.

---

# Matriz de rastreabilidade

Cada workflow do overview para as histórias que o implementam.

| Workflow (overview §) | Descrição                                    | Histórias                                         |
|-----------------------|----------------------------------------------|---------------------------------------------------|
| 5.1                   | Criação de post                              | US-011, US-014, US-022, US-032                    |
| 5.2                   | Interação positiva (like/comment/share)      | US-002, US-010, US-026, US-028                    |
| 5.3                   | Interação negativa (hide/skip/report)        | US-003, US-004, US-026, US-029, US-031            |
| 5.4                   | Dwell time tracking                          | US-008, US-026                                    |
| 5.5                   | Request do feed (candidate gen + ranking)    | US-001, US-006, US-007, US-009, US-013, US-017, US-023, US-024, US-030, US-031, US-032 |
| 5.6                   | Refresh de user embeddings                   | US-002, US-010, US-027, US-028, US-029            |
| 5.7                   | Cold start                                   | US-005, US-007, US-012, US-030                    |
| 6 (sinais e pesos)    | Tabela de sinais/pesos/decay                 | US-020, US-026                                    |
| 7 (decisões técnicas) | Config, SDK, Horizon, Redis                  | US-020, US-025                                    |
| 9 (métricas)          | CTR, diversidade, cobertura, sistema         | US-018, US-021, US-024                            |
| Observabilidade       | Traces, debug                                | US-016, US-017, US-018, US-019                    |
| Operação              | Kill-switch, A/B, backfill                   | US-021, US-022, US-023                            |
| Clusters (§4)         | Multi-vetor de interesse                     | US-006, US-033                                    |
| Avoid (§4)            | Vetor de aversão                             | US-003, US-004, US-029                            |

## Resumo por prioridade

| Prioridade | Count | IDs                                                                               |
|------------|-------|-----------------------------------------------------------------------------------|
| **Must**   | 19    | 001, 002, 003, 005, 006, 008, 011, 013, 016, 017, 019, 020, 022, 023, 025, 026, 027, 028, 032 |
| **Should** | 11    | 004, 010, 012, 014, 015, 018, 021, 024, 029, 030, 031                             |
| **Could**  | 4     | 007, 009, 033, 034                                                                |
| **Won't**  | 0     | —                                                                                 |

---

## Perguntas em aberto (escopo das histórias)

Questões que surgem da leitura cruzada overview ↔ histórias e que podem fazer histórias mudarem de forma/prioridade quando respondidas:

1. **US-009** ("por que vi isso?") é Could aqui — se for valorizada como feature de UX do workshop, promover para Should e impactar priorização de `ranking_traces` em tempo real.
2. **US-021** (A/B testing) — se o workshop não chegar a rodar experimentos reais, pode ser Won't. Mantive como Could por ser trivial de desligar.
3. **US-033** (clusters) — Could por complexidade de k-means em PHP. Se alguém trouxer `pgml` ou um script externo, pode subir para Should. Decisão do overview §4 já coloca como "nível avançado/roadmap".
4. **US-015** (remoção por reports) — o threshold exato está em `[DECISÃO PENDENTE §11.10]` do overview. Valor não muda a história, mas muda a aceitação.
5. **US-024** (random serving) — percentual de 1% é arbitrário; se o catálogo do workshop for pequeno demais, 1% pode dar 0 usuários. Considerar 5%.
