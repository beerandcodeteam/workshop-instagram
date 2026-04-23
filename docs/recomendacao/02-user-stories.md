# Sistema de Recomendação — Histórias de Usuário

> Derivado de `01-overview.md`. Este documento cobre as histórias de usuário da **evolução do sistema de recomendação** — não repete as histórias base do app (aquelas vivem em `docs/user-stories.md`). Leia o overview antes: termos como *long-term*, *short-term*, *avoid*, *MMR*, *candidate generation* e *ranking* são definidos lá.
>
> **Decisões de escopo adotadas** (confirmadas):
> - Tabela `follows` e "following pool" **fora** da v1.
> - Onboarding com seletor de interesses **fora** — cold start é 100% via trending.
> - Report = **hide duro + log** (sem fluxo de moderação).
> - **Sem A/B testing nem feature flags** — mudanças entram via deploy; pesos vivem em `config/recommender.php`.

---

## Personas

| Persona | Quem é |
|---|---|
| **Consumidor** | Usuário autenticado que rola o feed e interage com posts. É todo `User` que abre a home. |
| **Criador** | Usuário autenticado que publica posts. É o mesmo `User` que o Consumidor, mas quando atua como autor. |
| **Operador** | Time técnico / instrutor do workshop. Não tem papel dedicado no app base (não há role admin); opera via artisan, logs, dashboards e acesso ao banco. |
| **Sistema** | Jobs, observers, cron. Entidade lógica para histórias que descrevem comportamento automático (sem ator humano na loop). |

---

## Convenções

- **Prioridade (MoSCoW)**: `Must` = core da v1; `Should` = entra na v1 mas pode ser último; `Could` = roadmap curto; `Won't` = fora desta iteração.
- **Workflow relacionado**: seção do `01-overview.md` que descreve o fluxo técnico.
- **Dependências**: outras US que precisam estar prontas antes.
- **Numeração sequencial**: `US-001`, `US-002`, ... Sem prefixo de persona.
- Histórias não repetem contexto — quando duas dependem do mesmo fluxo, a segunda referencia a primeira.

---

## Índice

### Consumidor
- [US-001](#us-001--receber-feed-personalizado-ao-abrir-o-app) — Receber feed personalizado ao abrir o app *(Must)*
- [US-002](#us-002--curtir-um-post-ajusta-o-feed-em-tempo-real) — Curtir um post ajusta o feed em tempo real *(Must)*
- [US-003](#us-003--comentar-em-um-post-puxa-o-feed-com-peso-maior-que-like) — Comentar em um post puxa o feed com peso maior que like *(Must)*
- [US-004](#us-004--esconder-um-post-e-o-sistema-aprender-a-evitar-similares) — Esconder um post e o sistema aprender a evitar similares *(Must)*
- [US-005](#us-005--scroll-rapido-conta-como-sinal-negativo-leve) — Scroll rápido conta como sinal negativo leve *(Must)*
- [US-006](#us-006--dwell-time-influencia-o-short-term-sem-acao-explicita) — Dwell time influencia o short-term sem ação explícita *(Must)*
- [US-007](#us-007--nao-ver-o-mesmo-post-duas-vezes-em-uma-semana) — Não ver o mesmo post duas vezes em uma semana *(Must)*
- [US-008](#us-008--receber-feed-diverso-nao-10-posts-do-mesmo-assunto) — Receber feed diverso, não 10 posts do mesmo assunto *(Must)*
- [US-009](#us-009--primeira-sessao-aceitavel-sem-historico-cold-start) — Primeira sessão aceitável sem histórico (cold start) *(Must)*
- [US-010](#us-010--compartilhar-um-post-gera-sinal-positivo-maximo) — Compartilhar um post gera sinal positivo máximo *(Should)*
- [US-011](#us-011--denunciar-um-post-remove-do-feed-e-registra-log) — Denunciar um post remove do feed e registra log *(Should)*
- [US-012](#us-012--descobrir-posts-recem-publicados-exploration-boost) — Descobrir posts recém-publicados *(Should)*

### Criador
- [US-013](#us-013--post-novo-ganha-embedding-automatico-sem-bloquear-a-ui) — Post novo ganha embedding automático sem bloquear a UI *(Must)*
- [US-014](#us-014--post-novo-entra-no-feed-assim-que-o-embedding-esta-pronto) — Post novo entra no feed assim que o embedding está pronto *(Must)*
- [US-015](#us-015--cota-justa-de-aparicao-por-autor) — Cota justa de aparição por autor *(Should)*
- [US-016](#us-016--boost-de-freshness-em-posts-recentes) — Boost de freshness em posts recentes *(Should)*

### Operador
- [US-017](#us-017--entender-por-que-um-post-apareceu-no-feed-de-um-usuario) — Entender por que um post apareceu no feed de um usuário *(Must)*
- [US-018](#us-018--monitorar-filas-e-detectar-jobs-travados) — Monitorar filas e detectar jobs travados *(Must)*
- [US-019](#us-019--ajustar-pesos-e-parametros-de-ranking-via-config) — Ajustar pesos e parâmetros de ranking via config *(Must)*
- [US-020](#us-020--fazer-backfill-de-embeddings-em-massa) — Fazer backfill de embeddings em massa *(Must)*
- [US-021](#us-021--revisar-posts-reportados) — Revisar posts reportados *(Should)*
- [US-022](#us-022--monitorar-metricas-de-qualidade-do-sistema-em-dashboard) — Monitorar métricas de qualidade do sistema em dashboard *(Should)*

### Sistema
- [US-023](#us-023--gerar-embedding-multimodal-de-cada-post-via-job) — Gerar embedding multimodal de cada post via job *(Must)*
- [US-024](#us-024--atualizar-short-term-embedding-em-tempo-real-a-cada-interacao-positiva) — Atualizar short-term embedding em tempo real *(Must)*
- [US-025](#us-025--recalcular-long-term-embeddings-em-cron-diario) — Recalcular long-term embeddings em cron diário *(Must)*
- [US-026](#us-026--atualizar-avoid-embedding-a-cada-sinal-negativo-forte) — Atualizar avoid embedding a cada sinal negativo forte *(Must)*
- [US-027](#us-027--pre-computar-trending-pool-no-redis) — Pré-computar trending pool no Redis *(Must)*
- [US-028](#us-028--persistir-ranking-log-a-cada-requisicao-de-feed) — Persistir ranking log a cada requisição de feed *(Must)*
- [US-029](#us-029--degradar-graciosamente-quando-o-gemini-falhar) — Degradar graciosamente quando o Gemini falhar *(Must)*
- [US-030](#us-030--servir-baseline-aleatorio-em-1-do-trafego) — Servir baseline aleatório em 1% do tráfego *(Should)*
- [US-031](#us-031--agregar-metricas-hora-a-hora-em-job-de-rollup) — Agregar métricas hora a hora em job de rollup *(Should)*
- [US-032](#us-032--reconstruir-short-term-quando-o-redis-perder-dados) — Reconstruir short-term quando o Redis perder dados *(Should)*

---

# Consumidor

## US-001 — Receber feed personalizado ao abrir o app

**Persona:** Consumidor
**Prioridade:** Must
**Workflow relacionado:** 5.5 (Request do feed end-to-end)

**Como** Consumidor autenticado
**Quero** ao abrir o feed, ver posts relevantes para mim, não só os mais recentes
**Para que** o tempo na plataforma seja bem gasto

### Critérios de aceitação
- [ ] Ao acessar `/` logado, o feed retorna posts em ordem de relevância (não cronológica).
- [ ] A relevância é determinada pela combinação de long-term e short-term embeddings do usuário (α dinâmico conforme seção 4 do overview).
- [ ] O pool de candidatos vem de: ANN (pgvector) + trending pool (Redis). *Following e locality estão fora da v1.*
- [ ] Ranker aplica, nesta ordem: filtro avoid → filtros de negócio (visto, autor bloqueado, quota) → score composto → MMR (λ=0.7).
- [ ] Feed retorna pelo menos N=10 posts por página, sem posts duplicados entre páginas (`loadMore`).
- [ ] Latência p95 do `FeedService::build` ≤ 200ms.
- [ ] Se o usuário não tem nenhum embedding ainda (novo), cai no fluxo cold start (US-009).

### Notas técnicas
- Substitui a lógica atual de `App\Livewire\Pages\Feed\Index::render()` por uma chamada a `FeedService::build(User $user, int $perPage)`.
- `FeedService` lê `short_term` do Redis primeiro, fallback para `users.short_term_embedding` no Postgres, fallback para null.
- O `orderByRaw('... <=> ?::vector', [...])` atual vira **parte** do Stage 1 (ANN), não mais o ranking final.
- Remover do `Index`: `latest('posts.created_at')` como fallback genérico; o fallback agora é cold start via trending.
- Os eager-loads (`author`, `type`, `media`, `likes`, `withCount`) permanecem como hoje.

### Dependências
- US-013, US-014 (posts precisam ter embedding pra entrar no Stage 1).
- US-024 (short-term ser atualizado em tempo real).
- US-025 (long-term ser calculado).
- US-027 (trending pool existir no Redis).

### Métricas de sucesso
- CTR por posição (esperado: curva decrescente, posição 1 melhor que chronological baseline).
- Dwell time médio por impressão (esperado: aumento vs baseline atual).
- p95 de latência do FeedService <200ms.

---

## US-002 — Curtir um post ajusta o feed em tempo real

**Persona:** Consumidor
**Prioridade:** Must
**Workflow relacionado:** 5.2 (Interação positiva)

**Como** Consumidor
**Quero** que curtir um post influencie imediatamente o feed seguinte
**Para que** o sistema me leve mais rapidamente ao tipo de conteúdo que quero

### Critérios de aceitação
- [ ] Clicar no like toggle via `App\Livewire\Post\LikeButton` mantém o comportamento atual (toggle, unique por `user_id`+`post_id`).
- [ ] Após o like, uma linha é gravada em `interaction_events` com `type=like`, `weight=+1.0`.
- [ ] `UpdateShortTermEmbeddingJob` é enfileirado na fila `realtime` e atualiza o vetor do usuário no Redis em <500ms.
- [ ] Na próxima requisição do feed (`loadMore` ou refresh), o short-term já atualizado influencia o ranking.
- [ ] Unlike subtrai o peso do agregado: grava `interaction_events` com `type=unlike`, `weight=-1.0`.
- [ ] O antigo `LikeObserver::created/deleted` deixa de disparar `CalculateCentroidJob` e passa a delegar ao `InteractionService`.

### Notas técnicas
- Entidade `Like` continua existindo (tem UI própria e unique index). `interaction_events` é append-only em paralelo.
- `LikeObserver` é reescrito para chamar `InteractionService::record(..., InteractionType::Like)`; não dispara mais `CalculateCentroidJob`.
- `CalculateCentroidJob` e a coluna `users.embedding` ficam como legado durante migração. *Decisão de remover pendente (overview §11 item 12).*
- O `InteractionService` também dispara `MarkForLongTermRecomputeJob` (só seta flag Redis `long_term:dirty:{userId}`).

### Dependências
- US-001 (feed usando short-term).
- US-024 (job de atualização de short-term).

### Métricas de sucesso
- Tempo entre like e short-term atualizado no Redis: p95 <500ms.
- Correlação entre likes recentes e similaridade média dos próximos 10 posts servidos (esperado: forte).

---

## US-003 — Comentar em um post puxa o feed com peso maior que like

**Persona:** Consumidor
**Prioridade:** Must
**Workflow relacionado:** 5.2

**Como** Consumidor
**Quero** que comentar em um post influencie o feed mais que curtir
**Para que** o sistema reconheça que comentário é engajamento ativo

### Critérios de aceitação
- [ ] Ao criar comentário via `App\Livewire\Post\Comments`, uma linha é gravada em `interaction_events` com `type=comment`, `weight=+2.5`.
- [ ] `UpdateShortTermEmbeddingJob` é disparado com o peso 2.5.
- [ ] Se o usuário já curtiu e depois comentou, os pesos somam no agregado long-term (+1.0 + 2.5 = +3.5). Ver tabela §6.
- [ ] Comentário deletado (US-5.2 do app base) gera `interaction_events` com `type=uncomment`, `weight=-2.5`.

### Notas técnicas
- Criar `CommentObserver` novo (não existe hoje) que delega ao `InteractionService`.
- Comentário conta em long-term **e** short-term (diferente de dwell, que pesa menos em LT).

### Dependências
- US-002 (infra de InteractionService e short-term job).

### Métricas de sucesso
- Peso de comentário reflete na similaridade do feed mais forte que like (comparar grupos de usuários que só curtem vs. usuários que comentam).

---

## US-004 — Esconder um post e o sistema aprender a evitar similares

**Persona:** Consumidor
**Prioridade:** Must
**Workflow relacionado:** 5.3 (Interação negativa — hide)

**Como** Consumidor
**Quero** esconder um post que não me interessa
**Para que** eu não veja o post de novo **e** para que posts parecidos parem de aparecer

### Critérios de aceitação
- [ ] Existe um botão/ação "Não quero ver isso" no `PostCard` (componente novo ou extensão de `App\Livewire\Post\Card`).
- [ ] A ação grava `interaction_events` com `type=hide`, `weight=-3.0`.
- [ ] O `post_id` é adicionado ao set Redis `user:{id}:hidden` com TTL de 30 dias.
- [ ] `UpdateAvoidEmbeddingJob` atualiza `users.avoid_embedding` como média exponencial dos embeddings de posts hidden.
- [ ] Nas próximas requisições do feed, o próprio post não aparece (filtro duro via `hidden` set).
- [ ] Candidatos com `cos(post, avoid) > θ_avoid` (default 0.85) são descartados no Stage 2.
- [ ] O usuário recebe feedback visual imediato ("Post escondido").

### Notas técnicas
- `θ_avoid` em `config('recommender.avoid_threshold', 0.85)`.
- Hidden set Redis (`SADD user:{id}:hidden {post_id}` + `EXPIRE 2592000`).
- Avoid embedding **não** é subtraído do user vector — é usado só como filtro (ver overview §7).
- Um post só aparece em `hidden_set` por 30d; depois reaparece no pool (mas o avoid embedding já aprendeu a geometria).

### Dependências
- US-001 (feed usando filtros).
- US-026 (job de update de avoid).

### Métricas de sucesso
- Após N=5 hides consecutivos do mesmo cluster semântico, a fração de posts similares no feed seguinte cai >50%.
- Zero reaparições de posts hidden no prazo de 30 dias.

---

## US-005 — Scroll rápido conta como sinal negativo leve

**Persona:** Consumidor
**Prioridade:** Must
**Workflow relacionado:** 5.3, 5.4

**Como** Consumidor
**Quero** que o sistema perceba quando eu passo rápido por um post
**Para que** posts parecidos com os que eu ignoro apareçam menos

### Critérios de aceitação
- [ ] Client-side detecta via `IntersectionObserver` quando um post teve `dwell_ms < 1000` E o usuário scrollou ≥3 posts depois dele.
- [ ] Só nesse caso dispara o evento como `type=skip`, `weight=-0.3`.
- [ ] Skip isolado (<3 posts depois) **não** é registrado — evita falso positivo de scroll passageiro.
- [ ] Skips são enviados em batch para `/api/dwell` (mesmo endpoint do dwell — ver US-006).
- [ ] Skip **não** atualiza avoid imediatamente. Só acumula em `interaction_events`.

### Notas técnicas
- A distinção entre skip e dwell neutro é feita server-side no `RecordDwellEventsJob` a partir do `dwell_ms`.
- Skip não dispara `UpdateShortTermEmbeddingJob` nem `UpdateAvoidEmbeddingJob` — é sinal *apenas* pra análise offline, exceto que se o mesmo autor acumular >N skips em T tempo, o `UpdateAvoidEmbeddingJob` é disparado via US-026 (regra de acumulação).

### Dependências
- US-006 (infra de dwell tracking).

### Métricas de sucesso
- Queda da taxa de skip rápido no mesmo usuário ao longo de 7 dias (esperado: sistema aprende).

---

## US-006 — Dwell time influencia o short-term sem ação explícita

**Persona:** Consumidor
**Prioridade:** Must
**Workflow relacionado:** 5.4 (Dwell time tracking)

**Como** Consumidor
**Quero** que o sistema perceba quanto tempo eu fico olhando cada post
**Para que** mesmo sem curtir, eu receba mais do tipo de conteúdo que consumo

### Critérios de aceitação
- [ ] Client-side registra `enter_ts`/`exit_ts` por post no viewport (≥50% visível) via `IntersectionObserver`.
- [ ] A cada 5 posts observados ou a cada 10s (o que vier primeiro), envia batch `POST /api/dwell` com `[{post_id, dwell_ms, session_id}, ...]`.
- [ ] `DwellEventController` valida payload e enfileira `RecordDwellEventsJob` na fila `ingest`.
- [ ] O job mapeia `dwell_ms` → `weight` conforme tabela §6: 3-10s→+0.5, 10-30s→+1.0, >30s→+1.5. Neutro (1-3s) não grava.
- [ ] Dwell positivo influencia short-term diretamente via `UpdateShortTermEmbeddingJob`.
- [ ] Dwell positivo influencia long-term com peso ×0.3 (reduzido porque é implícito, ruidoso).
- [ ] Saturação em 30s: posts vistos por 5 minutos **não** contam como 5× mais interesse.

### Notas técnicas
- Rota nova: `POST /api/dwell` em `routes/api.php` (arquivo novo; hoje não existe).
- Middleware: `auth`, `throttle:60,1`.
- `session_id` vem do Livewire (gerado ao abrir o feed, regravado a cada reload).
- Ver tabela §6 do overview para curva completa de `dwell_ms` → `weight`.

### Dependências
- US-001 (feed precisa estar renderizando posts pra ter dwell).
- US-024 (job de short-term).

### Métricas de sucesso
- % de usuários com short-term atualizado só via dwell (sem like/comment).
- Correlação entre dwell médio e CTR nos dias seguintes.

---

## US-007 — Não ver o mesmo post duas vezes em uma semana

**Persona:** Consumidor
**Prioridade:** Must
**Workflow relacionado:** 5.5 (passos 4.3 e 5.2)

**Como** Consumidor
**Quero** não ver o mesmo post na mesma semana
**Para que** o feed não pareça repetitivo

### Critérios de aceitação
- [ ] Cada post servido é adicionado ao set Redis `user:{id}:seen` com TTL de 7 dias.
- [ ] No Stage 2 do ranking, qualquer candidato cujo `post_id` esteja em `seen` é descartado.
- [ ] Excepão: post que o usuário já curtiu/comentou/salvou pode reaparecer (não é mesmo tipo de "já viu"). *Decisão aplicada: v1 descarta mesmo nesses casos; revisitar se usuários reclamarem.*
- [ ] Se o pool pós-filtros ficar menor que `perPage`, o ranker busca mais candidatos (aumenta `LIMIT` do Stage 1) antes de retornar menos itens.

### Notas técnicas
- `seen` set em Redis: `SADD user:{id}:seen {post_id}` + `EXPIRE 604800`.
- No Stage 1 (ANN), é eficiente filtrar os seen diretamente na query: `WHERE p.id NOT IN (...)` — mas com seen grande, preferir filtrar no Stage 2.

### Dependências
- US-001.

### Métricas de sucesso
- Taxa de re-serving (mesmo post ao mesmo usuário em <7d) = 0.

---

## US-008 — Receber feed diverso, não 10 posts do mesmo assunto

**Persona:** Consumidor
**Prioridade:** Must
**Workflow relacionado:** 5.5 (passo 4.5), seção 4 (MMR)

**Como** Consumidor
**Quero** um feed com variedade de tópicos
**Para que** eu não caia numa bolha sobre um único assunto

### Critérios de aceitação
- [ ] Stage 2 aplica MMR com `λ=0.7` sobre os candidatos pós-filtros.
- [ ] Intra-list diversity (similaridade média entre pares de posts no feed) é **menor** que sem MMR (comparar antes/depois).
- [ ] Mesmo autor limitado a no máximo 3 posts nos primeiros 20 (cota).
- [ ] `λ` e a cota por autor vivem em `config/recommender.php` (`mmr_lambda`, `author_quota_per_20`).

### Notas técnicas
- MMR roda in-memory em PHP sobre o pool de ~1k candidatos.
- Algoritmo iterativo: começa com top-1 por score, a cada passo escolhe o candidato que maximiza `MMR(p) = λ·score(p) - (1-λ)·max sim(p, S)`.
- Ver fórmula completa em overview §4 e §5.5 passo 4.5.

### Dependências
- US-001.

### Métricas de sucesso
- Intra-list diversity (definido §9): aumento mensurável vs baseline.
- Gini de autores servidos: queda (menos concentração).

---

## US-009 — Primeira sessão aceitável sem histórico (cold start)

**Persona:** Consumidor
**Prioridade:** Must
**Workflow relacionado:** 5.7 (Cold start)

**Como** Consumidor recém-registrado (zero interações)
**Quero** abrir o feed e ver conteúdo razoável, ainda que genérico
**Para que** eu tenha motivo pra voltar

### Critérios de aceitação
- [ ] Usuário com `long_term = null` e `short_term = null` recebe feed 100% a partir do **trending pool**.
- [ ] MMR continua aplicado com `λ=0.7` (diversidade forte).
- [ ] Filtro de "já visto" continua ativo.
- [ ] Após ~5 interações positivas, `short_term` começa a existir e o feed passa a misturar trending + short-term.
- [ ] Após ~20 interações positivas ao longo de 3+ dias, `long_term` fica estável e o α dinâmico kick in normal.
- [ ] Não existe UI de onboarding / seletor de interesses na v1. *Decisão confirmada.*

### Notas técnicas
- α = 1.0 efetivo quando `short_term = null` (zero peso em short). Mas como `long_term` também é null, o fallback é trending puro.
- Cold start é transparente — nenhuma UI sinaliza "você é novo"; o feed simplesmente é menos personalizado no início.

### Dependências
- US-027 (trending pool existir).

### Métricas de sucesso
- Retenção D1 de usuários novos: ≥ baseline atual (não piorar).
- CTR em sessão 1 de usuário novo: ≥ CTR do feed cronológico atual.

---

## US-010 — Compartilhar um post gera sinal positivo máximo

**Persona:** Consumidor
**Prioridade:** Should
**Workflow relacionado:** 5.2

**Como** Consumidor
**Quero** compartilhar um post
**Para que** o sistema registre que é um endosso público (sinal mais forte)

### Critérios de aceitação
- [ ] Existe um botão "Compartilhar" no `PostCard`.
- [ ] A ação (que comportamento? — copiar link, abrir menu nativo, ou apenas registrar intenção) gera `interaction_events` com `type=share`, `weight=+4.0`.
- [ ] Share dispara `UpdateShortTermEmbeddingJob` com peso 4.0.
- [ ] Contribui para long-term (via `MarkForLongTermRecomputeJob`).
- [ ] **Decisão pendente herdada do overview §11 item 5**: a v1 modela share como linha em `interaction_events` (sem tabela `shares` dedicada). Se futuramente virar entidade, migrar os eventos históricos.

### Notas técnicas
- Comportamento do botão (copiar link / share API / DM) está fora do escopo deste doc — o foco aqui é só o sinal que o compartilhamento gera. Pode ser tão simples quanto "copiei o link e registrei o evento".
- Peso 4.0 é o mais forte positivo da tabela §6.

### Dependências
- US-002.

### Métricas de sucesso
- % de shares por impressão como métrica de engajamento forte.

---

## US-011 — Denunciar um post remove do feed e registra log

**Persona:** Consumidor
**Prioridade:** Should
**Workflow relacionado:** 5.3 (Report — decisão simplificada)

**Como** Consumidor
**Quero** reportar um post abusivo
**Para que** ele não apareça mais pra mim e o Operador possa revisar

### Critérios de aceitação
- [ ] Existe ação "Denunciar" no `PostCard`.
- [ ] Grava `interaction_events` com `type=report`, `weight=-10.0`.
- [ ] Adiciona `post_id` ao set `user:{id}:hidden` **permanentemente** (TTL muito longo, ex.: 10 anos ou sem TTL).
- [ ] Atualiza `avoid_embedding` (US-026).
- [ ] Usuário recebe confirmação: "Obrigado, vamos analisar".
- [ ] **Sem fluxo de moderação na v1**: só existe o log, que o Operador consulta via US-021. *Decisão confirmada.*

### Notas técnicas
- Reason opcional (motivo do report) pode ser adicionado em `interaction_events.metadata` JSONB. *Decisão pendente: adicionar campo `metadata jsonb null` na tabela?*
- Não existe fila `moderation` nem `ReportableObserver` — o report **é** o evento.

### Dependências
- US-004 (infra de hide).

### Métricas de sucesso
- Taxa de reports por 1k impressões (métrica de saúde geral do catálogo).

---

## US-012 — Descobrir posts recém-publicados (exploration boost)

**Persona:** Consumidor
**Prioridade:** Should
**Workflow relacionado:** 5.7 (post novo)

**Como** Consumidor
**Quero** ver conteúdo fresco mesmo que meu histórico não aponte pra ele
**Para que** eu descubra criadores/tópicos novos e o feed não fique estagnado

### Critérios de aceitação
- [ ] 10% do pool de candidatos do Stage 1 é reservado para posts criados nas últimas 24h, sorteados uniformemente.
- [ ] Esse slot de exploration entra independentemente do ANN — mesmo que o post tenha baixa similaridade com o user vector.
- [ ] O ranker (Stage 2) ainda aplica MMR e filtros, mas o score composto inclui um `β_recency` pequeno que favorece posts novos.

### Notas técnicas
- "10% do pool" significa: dos ~1000 candidatos, ~100 vêm de `SELECT * FROM posts WHERE created_at > now() - interval '24 hours' ORDER BY random() LIMIT 100`.
- `β_recency` e o decay vivem em `config('recommender.recency_boost')`.

### Dependências
- US-001.

### Métricas de sucesso
- Novelty (fração de impressões de posts <24h): ≥10%.
- Cobertura de catálogo em 7 dias: >60%.

---

# Criador

## US-013 — Post novo ganha embedding automático sem bloquear a UI

**Persona:** Criador
**Prioridade:** Must
**Workflow relacionado:** 5.1 (Criação de post)

**Como** Criador
**Quero** publicar um post sem esperar 1-3 segundos a mais pela chamada ao Gemini
**Para que** a experiência de criar seja rápida

### Critérios de aceitação
- [ ] `App\Observers\PostObserver::created` chama `dispatch()` (assíncrono) em vez de `dispatch_sync()`.
- [ ] `GeneratePostEmbeddingJob` roda na fila `embeddings`.
- [ ] A UI de criação (`App\Livewire\Post\CreateModal`) retorna imediatamente após o `Post` ser persistido, sem esperar o embedding.
- [ ] Job tem retry exponencial: 3 tentativas com backoff 10s/60s/300s.
- [ ] Após 3 falhas, vai para `failed_jobs` (Laravel padrão).

### Notas técnicas
- **Única mudança em código existente:** trocar `dispatch_sync(new GeneratePostEmbeddingJob(...))` por `GeneratePostEmbeddingJob::dispatch(...)` em `app/Observers/PostObserver.php`.
- `$tries = 3`, `$backoff = [10, 60, 300]` em `GeneratePostEmbeddingJob`.
- `PostObserver` já implementa `ShouldHandleEventsAfterCommit` — mantido.

### Dependências
- Nenhuma (é mudança isolada).

### Métricas de sucesso
- Latência de submit de post: p95 <300ms (hoje está em ~1-3s por conta do sync).
- Taxa de falha de `GeneratePostEmbeddingJob` <1%.

---

## US-014 — Post novo entra no feed assim que o embedding está pronto

**Persona:** Criador
**Prioridade:** Must
**Workflow relacionado:** 5.1

**Como** Criador
**Quero** que meu post apareça no feed dos usuários certos assim que for processado
**Para que** meu conteúdo alcance audiência sem latência de indexação

### Critérios de aceitação
- [ ] Um post é elegível para ranking **somente após** `post_embeddings` ter uma linha para ele (mantém comportamento do `join` atual).
- [ ] Entre o submit e a geração do embedding (janela de segundos), o post **não** aparece no feed. É invisibilidade temporária aceita.
- [ ] Após o embedding salvar, o post entra no pool ANN nas próximas requisições de feed (sem deploy, sem rebuild de índice — HNSW é incremental no pgvector).
- [ ] Não há cache stale: `Feed\Index` não cacheia lista de posts; consulta é sempre live.

### Notas técnicas
- O `join('post_embeddings', ...)` atual no `Feed\Index::render()` já garante que post sem embedding não aparece — preservar esse invariante no novo `FeedService`.
- Uma alternativa seria mostrar o post "pendente" pro próprio autor (feedback: "estamos processando"). *Decisão pendente: fora do escopo da v1.*

### Dependências
- US-013.

### Métricas de sucesso
- Tempo entre publish e primeira impressão pra outros usuários: p95 <30s.

---

## US-015 — Cota justa de aparição por autor

**Persona:** Criador
**Prioridade:** Should
**Workflow relacionado:** 5.5 (passo 4.3)

**Como** Criador menor
**Quero** não ser soterrado por criadores que publicam muito
**Para que** meu conteúdo tenha chance de ser descoberto

### Critérios de aceitação
- [ ] Nenhum autor aparece mais de 3 vezes nos primeiros 20 posts do feed de um usuário.
- [ ] Excedentes são removidos do top-20 (mas podem aparecer em páginas posteriores).
- [ ] Quota `author_quota_per_20=3` vive em `config/recommender.php`.

### Notas técnicas
- Filtro aplicado no Stage 2 (ranking), passo 4.3 do overview.
- Implementação: durante o MMR, tracker de `author_count` por user_id; descarta candidatos cujo autor já atingiu a quota nos top-20.

### Dependências
- US-008.

### Métricas de sucesso
- Gini de autores no top-20 servido: alvo <0.5.
- % de criadores únicos servidos por dia (alvo: >30% do catálogo de autores ativos).

---

## US-016 — Boost de freshness em posts recentes

**Persona:** Criador
**Prioridade:** Should
**Workflow relacionado:** 5.5 (passo 4.4, `β_recency`)

**Como** Criador que publica em horário de baixa audiência
**Quero** não ser penalizado por não ter engajamento nas primeiras horas
**Para que** meu post ainda tenha chance com quem abre o app depois

### Critérios de aceitação
- [ ] Score composto inclui um termo `β_recency · decay(age_hours)`.
- [ ] `decay(age_hours) = 2^(-age_hours / 24)` (half-life 24h).
- [ ] `β_recency` vive em `config('recommender.recency_weight', 0.1)`.
- [ ] Um post de 2h com similaridade X é ranqueado acima de um post de 48h com mesma similaridade X.

### Notas técnicas
- Diferente do "exploration slot" (US-012) que reserva 10% do *pool*. `β_recency` atua dentro do score de **todos** os candidatos.
- Valor 0.1 é chute inicial — ajustar via análise offline.

### Dependências
- US-001.

### Métricas de sucesso
- CTR médio de posts com 0-6h: comparar antes/depois do boost.
- Distribuição temporal de impressões por idade do post (esperado: cauda mais gorda em posts recentes).

---

# Operador

## US-017 — Entender por que um post apareceu no feed de um usuário

**Persona:** Operador
**Prioridade:** Must
**Workflow relacionado:** 4 ("Por que esse post?"), 5.5 (passo 5.1), 9

**Como** Operador debugando uma queixa ("por que me mostraram isso?")
**Quero** consultar o breakdown completo do score do post servido
**Para que** eu identifique a fonte (ANN? trending?) e qual feature dominou

### Critérios de aceitação
- [ ] Tabela `ranking_logs` existe com colunas: `user_id`, `post_id`, `position`, `source` (ann|trending|recency), `sim_long_term`, `sim_short_term`, `trending_score`, `recency_score`, `mmr_penalty`, `final_score`, `served_at`, `session_id`.
- [ ] Cada requisição de feed grava `perPage` linhas em `ranking_logs` (INSERT batch).
- [ ] Existe um comando artisan `php artisan recommender:explain {user_id} {post_id}` que imprime o breakdown.
- [ ] Em ambiente dev, acessando `/?debug=ranking` mostra o breakdown inline em cada card (campo `explain` no componente Livewire).
- [ ] Em prod, `?debug=ranking` é ignorado (flag por ambiente).

### Notas técnicas
- Tabela nova, particionável por `served_at` se volume crescer (§11 item 10 do overview).
- Índice em `(user_id, post_id, served_at desc)` pra lookup rápido.
- O componente `App\Livewire\Post\Card` recebe prop opcional `$explain` quando em debug mode.

### Dependências
- US-028 (sistema persistindo logs).

### Métricas de sucesso
- Tempo médio de Operador pra resolver queixa "por que X?": <5min.

---

## US-018 — Monitorar filas e detectar jobs travados

**Persona:** Operador
**Prioridade:** Must
**Workflow relacionado:** §3 (pipeline offline), §9 (técnicas)

**Como** Operador
**Quero** visualizar filas `embeddings`, `realtime`, `batch`, `ingest` com throughput, latência e falhas
**Para que** eu detecte cedo problemas (rate limit do Gemini, Redis lento, cron travado)

### Critérios de aceitação
- [ ] Todas as filas descritas no overview (`embeddings`, `realtime`, `batch`, `ingest`) existem e os jobs apontam pra `onQueue(...)` corretamente.
- [ ] O Operador tem visibilidade de: total de jobs por fila, jobs em execução, falhas recentes.
- [ ] Ao menos uma dashboard/CLI pra inspecionar `failed_jobs` com filtro por classe de job.
- [ ] **Decisão herdada do overview §11 item 1**: escolha de Horizon vs manter queue `database` + ferramentas minimas.

### Notas técnicas
- Se Horizon entrar: instalar `laravel/horizon`, `php artisan horizon:install`, configurar supervisors em `config/horizon.php`.
- Se não: criar um Livewire simples `App\Livewire\Pages\Admin\Queues` que lê `jobs` e `failed_jobs` diretamente.
- Rate-limit do Gemini: usar `Redis::throttle('gemini')->allow(N)->every(60)` dentro de `GeneratePostEmbeddingJob::handle()` — útil com ou sem Horizon.

### Dependências
- Todos os jobs (US-023 a US-028 etc).

### Métricas de sucesso
- MTTD (mean time to detect) de backlog crescente: <10min.
- Throughput de `GeneratePostEmbeddingJob`: consistente, sem picos.

---

## US-019 — Ajustar pesos e parâmetros de ranking via config

**Persona:** Operador
**Prioridade:** Must
**Workflow relacionado:** §4, §6, §7

**Como** Operador
**Quero** ajustar α, λ, θ_avoid, pesos de sinais, half-lifes, cotas sem deploy de código
**Para que** eu possa iterar no ranker com rapidez

### Critérios de aceitação
- [ ] Arquivo `config/recommender.php` existe com todos os parâmetros tunáveis, cada um com `env('...')` fallback.
- [ ] Parâmetros cobertos minimamente:
  - Pesos de sinais: `signal_weights.like`, `.comment`, `.share`, `.skip`, `.hide`, `.report`, `.dwell_short`, `.dwell_medium`, `.dwell_long`.
  - Decays: `half_life_long_days`, `half_life_short_hours`.
  - Mixing: `alpha_fresh_session`, `alpha_active_session`, `alpha_cold`.
  - MMR: `mmr_lambda`.
  - Filtros: `avoid_threshold`, `author_quota_per_20`, `seen_ttl_days`, `hide_ttl_days`.
  - Candidate gen: `ann_limit`, `trending_limit`, `exploration_fraction`.
  - Boost: `recency_weight`.
- [ ] Valores default ancorados nos valores sugeridos no overview.
- [ ] **Sem feature flag / A/B.** Mudança entra por deploy (via merge + `php artisan config:cache`). *Decisão confirmada.*

### Notas técnicas
- Nada de Redis pra override em runtime — config estática é suficiente para o workshop.
- Documentar cada chave no próprio arquivo em PHPDoc.

### Dependências
- Histórias de consumo de cada param (US-001 pra α/λ, US-004 pra θ_avoid, US-015 pra quota, etc).

### Métricas de sucesso
- Tempo entre decisão de tuning e rollout: <30min (só o deploy).

---

## US-020 — Fazer backfill de embeddings em massa

**Persona:** Operador
**Prioridade:** Must
**Workflow relacionado:** §5.1

**Como** Operador
**Quero** regerar embeddings de todos os posts (ex.: modelo mudou, bug no pipeline antigo)
**Para que** o catálogo fique consistente

### Critérios de aceitação
- [ ] O comando `app:generate-post-embeddings` **já existe** (`app/Console/Commands/GeneratePostEmbeddings.php`) e aceita `--force` e `--chunk=N`.
- [ ] Mantém a semântica atual: por default só processa posts sem embedding; `--force` apaga e reprocessa.
- [ ] Passa a **enfileirar** (`dispatch`) em vez de `dispatch_sync` para aproveitar concorrência da fila `embeddings` (coerente com US-013).
- [ ] Progress bar preservada; relatório final de sucesso/falha.

### Notas técnicas
- A troca de `dispatch_sync` por `dispatch` no command exige observação: pra backfill a expectativa é fila processar, não tudo na CLI. Se alguém quiser sync pra debug, adicionar `--sync` flag.
- Para backfill grande, aumentar throttle do Gemini conforme US-018.

### Dependências
- US-013 (job preparado pra rodar em fila).

### Métricas de sucesso
- Backfill de 1250 posts (seed completo) termina em <20min.
- Zero posts sem embedding após o comando concluir com sucesso.

---

## US-021 — Revisar posts reportados

**Persona:** Operador
**Prioridade:** Should
**Workflow relacionado:** 5.3 (report simplificado)

**Como** Operador
**Quero** listar os posts que acumularam mais reports
**Para que** eu decida manualmente se algum deve ser removido da plataforma

### Critérios de aceitação
- [ ] Existe uma query ou comando artisan `php artisan recommender:reports` que lista posts ordenados por contagem de `interaction_events WHERE type='report'` descendente, nas últimas 7 dias.
- [ ] Mostra: `post_id`, contagem de reports, autor, corpo truncado, link pro post.
- [ ] **Sem UI de revisão, sem fluxo de aprovação/rejeição.** O Operador age manualmente (SQL `DELETE` ou via tinker) se precisar. *Decisão confirmada.*

### Notas técnicas
- Alternativamente, um `App\Livewire\Pages\Admin\Reports` — mas como não há role admin, rota fica atrás de middleware `can:admin` com gate ad-hoc. *Decisão pendente: vale criar tela ou só CLI?*

### Dependências
- US-011.

### Métricas de sucesso
- Backlog de posts com ≥N reports não revisado por >24h: 0.

---

## US-022 — Monitorar métricas de qualidade do sistema em dashboard

**Persona:** Operador
**Prioridade:** Should
**Workflow relacionado:** §9

**Como** Operador / instrutor do workshop
**Quero** visualizar CTR por posição, dwell médio, diversidade Gini, cobertura, novelty
**Para que** eu avalie se o ranker está melhorando ou regredindo

### Critérios de aceitação
- [ ] Existe uma view `App\Livewire\Pages\Admin\Metrics` (ou equivalente) com cards pras métricas de §9 do overview.
- [ ] Métricas calculadas a partir de `ranking_logs` + `interaction_events` via query agregada (ou lidas de tabela rollup — ver US-031).
- [ ] Período ajustável: 1h, 24h, 7d.
- [ ] Comparação vs 7 dias anteriores (delta %).

### Notas técnicas
- **Decisão pendente (overview §11 item 9)**: Grafana externo vs view Livewire inline vs Telescope. Para workshop, view Livewire é suficiente e didática.
- Se as queries forem pesadas, pré-agregar em tabela `ranking_metrics_hourly` via cron (ver US-031).

### Dependências
- US-017, US-028, US-031.

### Métricas de sucesso
- Operador consegue responder "o ranker melhorou esta semana?" em <1min.

---

# Sistema

## US-023 — Gerar embedding multimodal de cada post via job

**Persona:** Sistema
**Prioridade:** Must
**Workflow relacionado:** 5.1

**Como** Sistema
**Quero** gerar embeddings multimodais de posts criados
**Para que** o Stage 1 do ranking tenha vetores pra comparar

### Critérios de aceitação
- [ ] `GeneratePostEmbeddingJob` monta `parts` com `body` (se não vazio) e cada `post_media` como `inline_data` base64.
- [ ] Chama `GeminiEmbeddingService::embed($parts, 'RETRIEVAL_DOCUMENT')` — task_type fixo para posts.
- [ ] Grava em `post_embeddings(post_id, embedding)`.
- [ ] Roda na fila `embeddings`.
- [ ] `$tries = 3`, backoff `[10, 60, 300]`.

### Notas técnicas
- **Esta história é 90% "já existe"** — o job `App\Jobs\GeneratePostEmbeddingJob` está implementado. O trabalho é (a) trocar `dispatch_sync` por `dispatch` no observer (US-013), (b) adicionar `onQueue('embeddings')`, (c) adicionar retry config.
- Task_type `RETRIEVAL_DOCUMENT` já é o default do service.

### Dependências
- Nenhuma (existe).

### Métricas de sucesso
- Taxa de sucesso >99%.
- Latência p95 do job: <3s.

---

## US-024 — Atualizar short-term embedding em tempo real a cada interação positiva

**Persona:** Sistema
**Prioridade:** Must
**Workflow relacionado:** 5.6 (Short-term)

**Como** Sistema
**Quero** atualizar o vetor short-term do usuário no Redis a cada like/comment/share/dwell positivo
**Para que** o feed da próxima requisição reflita o interesse atual

### Critérios de aceitação
- [ ] `UpdateShortTermEmbeddingJob($userId, $postId, $weight)` roda na fila `realtime`.
- [ ] Lê `v_old` do Redis `short_term:{userId}`; fallback `users.short_term_embedding`; fallback vetor zero.
- [ ] Lê `v_post` de `post_embeddings WHERE post_id = ?`.
- [ ] Aplica `v_new = normalize(decay · v_old + weight · v_post)` onde `decay = 2^(-age_h / 6)`.
- [ ] Grava `short_term:{userId}` no Redis com `EX 172800` (48h).
- [ ] A cada N=20 atualizações, snapshot no Postgres (`users.short_term_embedding`, `users.short_term_updated_at`). *Valor N é decisão pendente §11 item 11.*
- [ ] p95 do job: <500ms.

### Notas técnicas
- Novas colunas em `users`: `short_term_embedding vector(1536) null`, `short_term_updated_at timestamp null`.
- Formato Redis: serialização binária do array floats (PACK) ou JSON string. *Decisão pendente: formato de serialização.*
- Half-life 6h é parametrizado (`config('recommender.half_life_short_hours')`).

### Dependências
- US-023 (post_embeddings populado).
- Redis ativo.

### Métricas de sucesso
- p95 latência do job <500ms.
- Hit rate de cache Redis para short-term ≥95%.

---

## US-025 — Recalcular long-term embeddings em cron diário

**Persona:** Sistema
**Prioridade:** Must
**Workflow relacionado:** 5.6 (Long-term)

**Como** Sistema
**Quero** recalcular o vetor long-term de usuários ativos diariamente
**Para que** a "personalidade" do usuário no sistema reflita os últimos 90-180 dias com decay correto

### Critérios de aceitação
- [ ] Cron diário às 03:00 BRT dispara `RefreshLongTermEmbeddingsJob`.
- [ ] Processa apenas usuários marcados como dirty (set Redis `long_term:dirty`) em chunks de 100.
- [ ] Para cada usuário: carrega `interaction_events` dos últimos 180d com `weight > 0`, calcula `v_user = normalize(Σ w_i · embedding_i / Σ w_i)` com `w_i = weight_signal · 2^(-age_days / 30)`.
- [ ] `UPDATE users SET long_term_embedding = ?, long_term_updated_at = now()`.
- [ ] Remove o usuário do set dirty após sucesso.
- [ ] Job roda na fila `batch`.

### Notas técnicas
- Nova coluna em `users`: `long_term_embedding vector(1536) null`, `long_term_updated_at timestamp null`.
- Considerar renomear a `users.embedding` existente para `users.long_term_embedding` OU manter ambas durante migração — herda §11 item 12 do overview.
- A query por `interaction_events` pode ser pesada; usar índice `(user_id, created_at)` já planejado em §6.

### Dependências
- US-024 (dirty flag é setado pelo short-term job).
- Tabela `interaction_events` existir.

### Métricas de sucesso
- Duração total do batch diário: <30min para 300 usuários do seed; <6h pra 100k usuários hipotéticos.
- Zero usuários com `long_term_updated_at > 48h` e `dirty=true`.

---

## US-026 — Atualizar avoid embedding a cada sinal negativo forte

**Persona:** Sistema
**Prioridade:** Must
**Workflow relacionado:** 5.3, §4 (User avoid embedding)

**Como** Sistema
**Quero** manter um vetor "avoid" por usuário, agregando hides/reports e skips acumulados
**Para que** o ranker descarte posts semanticamente similares ao que o usuário rejeitou

### Critérios de aceitação
- [ ] `UpdateAvoidEmbeddingJob($userId, $postId, $weight)` roda na fila `realtime`.
- [ ] Dispara para: `hide` (+ imediato), `report` (+ imediato), `skip` (só se acumular N=5 skips do mesmo autor em 24h).
- [ ] `v_avoid_new = normalize(decay · v_avoid_old + |weight| · v_post)` — usa peso absoluto porque é média, não subtração.
- [ ] Decay do avoid: half-life 30 dias (mesmo do LT).
- [ ] Grava em `users.avoid_embedding`.

### Notas técnicas
- Nova coluna em `users`: `avoid_embedding vector(1536) null`.
- Avoid é usado **só como filtro** no ranker (US-004 overview §7), não subtraído.
- Quando o usuário remove um hide (se existir UI pra isso no futuro), o job recalcula do zero a partir de `interaction_events`.

### Dependências
- US-004, US-011.

### Métricas de sucesso
- Posts descartados por avoid filter / total candidatos: métrica de saúde, esperado 1-5% quando usuário acumula hides.

---

## US-027 — Pré-computar trending pool no Redis

**Persona:** Sistema
**Prioridade:** Must
**Workflow relacionado:** 5.5 (passo 3.2), 5.7

**Como** Sistema
**Quero** manter um trending pool atualizado pra alimentar cold start e o Stage 1 do ranking
**Para que** o feed não dependa 100% de user embedding

### Critérios de aceitação
- [ ] Cron a cada 15min dispara `ComputeTrendingPoolJob` na fila `batch`.
- [ ] Calcula score de trending por post: `score = likes_last_24h · 1 + comments_last_24h · 2.5 + shares_last_24h · 4 + dwell_positive_last_24h · 0.5` (coerente com pesos da §6).
- [ ] Grava no Redis zset `trending:global` com `ZADD` — top 500 posts.
- [ ] TTL do zset: 1h (cron roda antes de expirar).
- [ ] Feed consome com `ZREVRANGE trending:global 0 99`.

### Notas técnicas
- Considerar trending segmentado por `post_type` (`trending:text`, `trending:image`, `trending:video`) para garantir diversidade — *decisão pendente*.
- Posts com `report >= X` no período são excluídos do trending.

### Dependências
- Redis ativo.

### Métricas de sucesso
- Atraso máximo de entrada em trending após post viralizar: <15min.
- Latência de `ZREVRANGE`: <5ms.

---

## US-028 — Persistir ranking log a cada requisição de feed

**Persona:** Sistema
**Prioridade:** Must
**Workflow relacionado:** §4 (Feedback loop), 5.5 (passo 5)

**Como** Sistema
**Quero** gravar uma linha por candidato ranqueado servido
**Para que** Operador e analistas consigam avaliar o ranker offline

### Critérios de aceitação
- [ ] Ao final de cada `FeedService::build`, faz `INSERT` batch em `ranking_logs` (uma linha por post servido).
- [ ] Colunas: `user_id`, `post_id`, `position`, `source` (ann|trending|recency), `sim_long_term`, `sim_short_term`, `trending_score`, `recency_score`, `mmr_penalty`, `final_score`, `served_at`, `session_id`.
- [ ] INSERT não pode exceder 50ms (batch único, sem transação).
- [ ] Retenção: queremos pelo menos 90 dias. Mais que isso, rolar pra arquivo ou purgar. *Decisão herdada §11 item 10.*

### Notas técnicas
- Nova migration pra `ranking_logs`.
- Índice `(user_id, post_id, served_at desc)` pra lookup por US-017.
- Índice `(served_at)` pra métricas agregadas.
- Considerar particionamento por mês se volume crescer.

### Dependências
- US-001 (o ranker real).

### Métricas de sucesso
- Overhead de logging no `build`: <20ms.
- 100% das requisições com log persistido (zero drop silencioso).

---

## US-029 — Degradar graciosamente quando o Gemini falhar

**Persona:** Sistema
**Prioridade:** Must
**Workflow relacionado:** 5.1 (retry), §8 (trade-offs)

**Como** Sistema
**Quero** não quebrar a aplicação quando a API do Gemini estiver indisponível
**Para que** posts continuem sendo publicados e o feed continue servindo

### Critérios de aceitação
- [ ] `GeneratePostEmbeddingJob` falha com retry exponencial (3x), depois cai em `failed_jobs` sem crashar o processo.
- [ ] Se o embedding não existir, o post simplesmente não entra no feed (comportamento já garantido pelo `join` existente).
- [ ] `FeedService::build` **não** chama o Gemini na request. Não há dependência sync de embedding API no hot path.
- [ ] Se o Redis cair: `FeedService` lê `short_term` do Postgres (fallback); sem short-term, cai em α=1.0 e usa só long-term; sem long-term, cai em trending (cold start).
- [ ] Alerta configurável quando taxa de falha do Gemini >1% em 15min. *Decisão pendente: canal de alerta (Slack? log?).*

### Notas técnicas
- Rate limit do Gemini: `Redis::throttle('gemini')->allow(60)->every(60)->then(...)`. Ou via Horizon (se instalado).
- Timeout do HTTP client: 30s (hoje é default Laravel, provavelmente ~30s; confirmar).

### Dependências
- US-023.

### Métricas de sucesso
- Disponibilidade do feed durante outage do Gemini: 100%.
- Tempo médio até embeddings pendentes serem processados após Gemini voltar: <10min.

---

## US-030 — Servir baseline aleatório em 1% do tráfego

**Persona:** Sistema
**Prioridade:** Should
**Workflow relacionado:** §4 (Random serving baseline)

**Como** Sistema
**Quero** servir feed em ordem aleatória uniforme para 1% dos usuários
**Para que** eu colete sinais não-enviesados pelo próprio ranker

### Critérios de aceitação
- [ ] Determinação: `user_id % 100 == salt_of_the_day` seleciona o 1% (com salt rotativo diário pra não punir sempre os mesmos).
- [ ] Usuários no baseline recebem feed aleatório **sobre o trending pool** (não sobre o catálogo inteiro — muito ruído).
- [ ] `ranking_logs.source = 'random_baseline'` é gravado para essas impressões.
- [ ] Usuários do baseline recebem **MMR, filtros de visto/avoid/autor bloqueado** normalmente (não viramos hostil).

### Notas técnicas
- Salt em `cache()->remember('random_salt:'.date('Y-m-d'), 86400, fn () => random_int(0, 99))`.
- Separável do ranker via branch no `FeedService::build`.

### Dependências
- US-001, US-027, US-028.

### Métricas de sucesso
- % de impressões no bucket baseline: entre 0.8% e 1.2% (tolerância).
- Diferença estatística significativa (p<0.05) de CTR entre ranker e baseline — prova que o ranker agrega valor.

---

## US-031 — Agregar métricas hora a hora em job de rollup

**Persona:** Sistema
**Prioridade:** Should
**Workflow relacionado:** §9

**Como** Sistema
**Quero** pré-agregar métricas da §9 em uma tabela/zset
**Para que** dashboards (US-022) leiam rápido sem escanear milhões de linhas

### Critérios de aceitação
- [ ] Cron horário dispara `RollupInteractionMetricsJob`.
- [ ] Calcula e persiste, por hora: CTR por posição, dwell médio, skip rate, Gini de autores, cobertura, novelty, intra-list diversity.
- [ ] Grava em `ranking_metrics_hourly(period_start, metric_key, metric_value)` ou em Redis zset.
- [ ] p95 de execução do job: <2min com 24h de dados.

### Notas técnicas
- Tabela nova: `ranking_metrics_hourly`. Esquema decidido no momento da implementação.
- Se Redis zset: `ZADD metrics:ctr_pos1 {epoch} {value}`.

### Dependências
- US-028.

### Métricas de sucesso
- Dashboard US-022 renderiza em <1s mesmo com 90d de histórico.

---

## US-032 — Reconstruir short-term quando o Redis perder dados

**Persona:** Sistema
**Prioridade:** Should
**Workflow relacionado:** §8 (staleness short-term)

**Como** Sistema
**Quero** reconstruir `short_term:{userId}` quando o Redis cair e reiniciar vazio
**Para que** o short-term volte sem esperar o usuário interagir dezenas de vezes

### Critérios de aceitação
- [ ] `RebuildShortTermFromEventsJob($userId)` reconstrói o short-term a partir de `interaction_events` dos últimos 48h com `weight > 0`.
- [ ] Aplicado quando: (a) cache miss em `short_term:{userId}` E (b) `users.short_term_updated_at < now() - 1h`.
- [ ] Após reconstruir, grava no Redis e no Postgres.
- [ ] Despachado via fila `realtime` (alta prioridade — usuário está esperando o feed).

### Notas técnicas
- Esta história **não** cobre o snapshot incremental a cada N interações — isso está em US-024.
- Em um cenário de outage geral, essa avalanche de jobs pode saturar o Redis; considerar throttle por usuário.

### Dependências
- US-024, tabela `interaction_events`.

### Métricas de sucesso
- Tempo até short-term reconstruído após cache miss: <2s no hot path (assíncrono, feed usa fallback Postgres nesse meio-tempo).

---

# Matriz de rastreabilidade

Mapeia cada seção de workflow do `01-overview.md` para as histórias que a cobrem.

| Workflow (overview) | Histórias que o implementam | Cobertura |
|---|---|---|
| 5.1 — Criação de post | US-013, US-014, US-023 | Completa |
| 5.2 — Interação positiva (like/comment/share) | US-002, US-003, US-010, US-024, US-025 | Completa |
| 5.3 — Interação negativa (hide/skip/report) | US-004, US-005, US-011, US-026 | Completa (report simplificado) |
| 5.4 — Dwell time tracking | US-005, US-006 | Completa |
| 5.5 — Request do feed end-to-end | US-001, US-007, US-008, US-012, US-015, US-016, US-028 | Completa |
| 5.6 — Refresh de user embeddings | US-024 (short-term), US-025 (long-term) | Completa |
| 5.7 — Cold start | US-009 (usuário novo), US-012 (post novo) | Completa |
| 6 — Sinais e pesos | US-002 a US-006, US-010, US-011, US-019 | Completa |
| 4 — Random serving baseline | US-030 | Completa |
| 4 — "Por que esse post?" | US-017 | Completa |
| 7 — Decisões técnicas (config tuning) | US-019 | Parcial (não aborda task_types específicos ainda) |
| 8 — Trade-offs (degradação graciosa) | US-029, US-032 | Completa |
| 9 — Métricas de sucesso | US-022, US-031 | Completa |

### Fluxos fora do escopo da v1 (confirmado)
- Following pool (overview §3, §5.5 passo 3.3) — sem US correspondente.
- Onboarding / seletor de interesses (overview §5.7) — sem US correspondente.
- Locality pool (overview §3, §5.5) — sem US correspondente.
- Fluxo completo de moderação (overview §5.3) — sem US correspondente; cobertura mínima via US-011 e US-021.
- Interest clusters / multi-interest k-means (overview §4) — roadmap, sem US.
- Feature flags / A/B testing — sem US correspondente (decisão confirmada).

---

# Apêndice — status das histórias

| ID | História | Persona | Prioridade | Depende de |
|---|---|---|---|---|
| US-001 | Feed personalizado ao abrir o app | Consumidor | Must | US-013, US-024, US-025, US-027 |
| US-002 | Like ajusta feed em tempo real | Consumidor | Must | US-024 |
| US-003 | Comentário com peso maior | Consumidor | Must | US-002 |
| US-004 | Hide + avoid similares | Consumidor | Must | US-026 |
| US-005 | Skip rápido como sinal negativo | Consumidor | Must | US-006 |
| US-006 | Dwell time influencia short-term | Consumidor | Must | US-024 |
| US-007 | Não repetir post em 7 dias | Consumidor | Must | US-001 |
| US-008 | Feed diverso (MMR) | Consumidor | Must | US-001 |
| US-009 | Cold start via trending | Consumidor | Must | US-027 |
| US-010 | Share como sinal máximo | Consumidor | Should | US-002 |
| US-011 | Report = hide duro + log | Consumidor | Should | US-004 |
| US-012 | Descobrir posts novos | Consumidor | Should | US-001 |
| US-013 | Embedding assíncrono na criação | Criador | Must | — |
| US-014 | Post entra no feed após embedding | Criador | Must | US-013 |
| US-015 | Cota por autor | Criador | Should | US-008 |
| US-016 | Boost de freshness | Criador | Should | US-001 |
| US-017 | Debugar ranking via logs | Operador | Must | US-028 |
| US-018 | Monitorar filas | Operador | Must | (todos jobs) |
| US-019 | Pesos via config | Operador | Must | — |
| US-020 | Backfill em massa | Operador | Must | US-013 |
| US-021 | Revisar reports | Operador | Should | US-011 |
| US-022 | Dashboard de métricas | Operador | Should | US-031 |
| US-023 | Job de embedding de post | Sistema | Must | — |
| US-024 | Short-term em tempo real | Sistema | Must | US-023 |
| US-025 | Long-term em cron diário | Sistema | Must | US-024 |
| US-026 | Avoid em sinais negativos | Sistema | Must | US-004 |
| US-027 | Trending pool no Redis | Sistema | Must | — |
| US-028 | Persistir ranking log | Sistema | Must | US-001 |
| US-029 | Degradação graciosa | Sistema | Must | US-023 |
| US-030 | Random baseline 1% | Sistema | Should | US-001 |
| US-031 | Rollup horário | Sistema | Should | US-028 |
| US-032 | Reconstruir short-term | Sistema | Should | US-024 |
