## Phase 7 — Observabilidade de ranking e debug

**Histórias:** US-009, US-016, US-017.

### 7.1 `recommendation_logs` + trace logger (US-017)
- [ ] **7.1.1** Migration `recommendation_logs` conforme schema doc (request_id UUID, scores_breakdown jsonb).
- [ ] **7.1.2** Model `RecommendationLog` + relações.
- [ ] **7.1.3** `PersistRankingTracesJob` na fila `traces` — escreve em batch ao fim do `feedFor`.
- [ ] **7.1.4** `PurgeRecommendationLogsJob` — `Schedule::dailyAt('04:00')` deleta onde `created_at < now() - 7 days`.
- **Pest tests (`tests/Feature/Rec/RankingTraceLoggerTest.php`):**
    - [ ] `feed_request_emits_n_traces`.
    - [ ] `trace_includes_source_score_and_final_position`.
    - [ ] `traces_older_than_7_days_are_purged`.
    - [ ] `filtered_candidate_is_logged_with_filtered_reason`.

### 7.2 Comando `rec:trace` (US-016)
- [ ] **7.2.1** `rec:trace {user_id} {post_id} [--request=ID]` — lê `recommendation_logs`, formata em tabela ASCII.
- [ ] **7.2.2** Suporte a flag `--negative` que mostra motivo de filtro se post NÃO foi recomendado.
- **Pest tests (`tests/Feature/Rec/RecTraceCommandTest.php`):**
    - [ ] `command_outputs_scores_and_position_for_user_post_pair`.
    - [ ] `command_shows_filtered_reason_for_excluded_candidates`.
    - [ ] `command_handles_expired_trace_gracefully` (>7d).

### 7.3 "Por que vi isso?" (US-009, Could)
- [ ] **7.3.1** Ação em menu do `post.card` que abre modal com explicação humanizada baseada em `recommendation_logs.recommendation_source_id`.
- [ ] **7.3.2** Mapa slug → frase em `config/recommendation.php` (ex.: `ann_short_term` → "parecido com o que você curtiu nas últimas horas").
- **Pest tests (`tests/Feature/Rec/WhyDidISeeThisTest.php`):**
    - [ ] `modal_renders_human_readable_reason`.
    - [ ] `modal_handles_missing_trace_gracefully` (trace purgado): mostra "Não temos mais essa informação".

---

