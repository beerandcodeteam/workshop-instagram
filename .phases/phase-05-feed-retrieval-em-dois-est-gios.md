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

