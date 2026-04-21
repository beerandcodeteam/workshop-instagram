## Phase 10 — Interest clusters (nível avançado)

**Histórias:** US-033, US-006 (parte de cluster coverage).

### 10.1 Schema `user_interest_clusters`
- [ ] **10.1.1** Migration + model `UserInterestCluster`.
- [ ] **10.1.2** HNSW em `user_interest_clusters.embedding`.
- **Pest tests (`tests/Feature/Rec/InterestClustersSchemaTest.php`):**
    - [ ] `table_and_hnsw_index_exist`.

### 10.2 `RefreshInterestClustersJob` (US-033)
- [ ] **10.2.1** k-means em PHP puro (lento mas dentro do workshop é OK) com silhouette score para escolher k ∈ [3..7].
- [ ] **10.2.2** Entrada: embeddings de posts com sinais positivos dos últimos 90d.
- [ ] **10.2.3** Apenas roda se `count >= 30`.
- [ ] **10.2.4** `Schedule::job(...)->weekly()` + trigger on `count_delta > 20`.
- **Pest tests (`tests/Feature/Rec/RefreshInterestClustersJobTest.php`):**
    - [ ] `job_creates_3_to_7_clusters_per_eligible_user`.
    - [ ] `job_skips_users_below_interaction_threshold`.
    - [ ] `cluster_weights_sum_to_1`.
    - [ ] `sample_count_is_populated`.
    - [ ] `replaces_not_updates_existing_rows` (delete + insert, não update in-place).

### 10.3 `CandidateGenerator::annByClusters`
- [ ] **10.3.1** Para cada cluster do user, pega top-100 posts por `embedding <=> cluster.embedding`.
- [ ] **10.3.2** Limit global: no máximo 300 do conjunto de clusters.
- **Pest tests (`tests/Feature/Rec/AnnByClustersTest.php`):**
    - [ ] `generator_returns_candidates_from_each_cluster_proportional_to_weight`.
    - [ ] `generator_noops_if_user_has_no_clusters`.

### 10.4 Cluster coverage no feed (US-006)
- [ ] **10.4.1** MMR estendido ou regra pós-quota que garante ≥70% dos clusters ativos no top-20.
- **Pest tests (`tests/Feature/Rec/ClusterCoverageTest.php`):**
    - [ ] `top_20_represents_at_least_70_percent_of_user_clusters` (para usuários com ≥3 clusters).

---

