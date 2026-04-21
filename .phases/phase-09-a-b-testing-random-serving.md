## Phase 9 — A/B testing + random serving

**Histórias:** US-021, US-024.

### 9.1 `recommendation_experiments` + assignment
- [ ] **9.1.1** Migration `recommendation_experiments` conforme schema doc.
- [ ] **9.1.2** `ExperimentService::variantFor(User $user, string $experiment): string` — hash determinístico (ou lê DB).
- **Pest tests (`tests/Feature/Rec/ExperimentAssignmentTest.php`):**
    - [ ] `same_user_gets_same_variant_within_window`.
    - [ ] `variants_distribute_roughly_uniformly`.

### 9.2 Random serving 1% (US-024)
- [ ] **9.2.1** Checagem no início do `feedFor` — 1% dos usuários (rotação diária por `hash(user_id + day) % 100 < control_pct`) recebe feed cronológico.
- [ ] **9.2.2** `recommendation_logs.experiment_variant = 'control'` para esse grupo.
- **Pest tests (`tests/Feature/Rec/RandomServingTest.php`):**
    - [ ] `control_group_receives_chronological_feed`.
    - [ ] `control_assignment_rotates_daily`.
    - [ ] `approximately_1_percent_of_users_assigned_to_control` (tolerance).

### 9.3 Variantes de ranking (US-021)
- [ ] **9.3.1** Config `recommendation.experiments['ranking_formula_v2']` → fórmula alternativa no `Ranker`.
- [ ] **9.3.2** Métricas quebradas por variante no dashboard (US-018).
- **Pest tests (`tests/Feature/Rec/RankingVariantTest.php`):**
    - [ ] `variant_b_uses_alternative_scoring_formula`.
    - [ ] `ranking_logs_record_variant_served`.

---

