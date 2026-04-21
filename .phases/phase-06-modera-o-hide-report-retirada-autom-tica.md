## Phase 6 — Moderação: hide, report, retirada automática

**Histórias:** US-003, US-004, US-015.

### 6.1 Ação Hide (US-003)
- [ ] **6.1.1** Botão "Esconder" em `post.card` (menu "..."); Livewire action cria `PostInteraction(kind=hide, weight=-1.5)`.
- [ ] **6.1.2** Adiciona `post_id` em `rec:user:{id}:hidden` (set Redis persistente).
- [ ] **6.1.3** `CandidateGenerator::generate` consulta esse set em filtro duro.
- **Pest tests (`tests/Feature/Rec/HidePostTest.php`):**
    - [ ] `hiding_a_post_creates_hide_interaction`.
    - [ ] `hidden_post_does_not_appear_in_next_feed`.
    - [ ] `hide_requires_authentication`.

### 6.2 Tabela `reports` + ReportObserver (US-004)
- [ ] **6.2.1** Migration `reports` conforme schema doc.
- [ ] **6.2.2** Model `Report` com relações + factory.
- [ ] **6.2.3** Botão "Reportar" em `post.card`, modal com motivos.
- [ ] **6.2.4** `ReportForm` Livewire Form Object com `post_id`, `reason`, `details`.
- [ ] **6.2.5** Grava `Report` + `PostInteraction(kind=report, weight=-3.0)`.
- [ ] **6.2.6** `ReportObserver::created` incrementa `posts.reports_count` (ver decisão §11.3 do schema doc).
- **Pest tests (`tests/Feature/Rec/ReportPostTest.php`):**
    - [ ] `reporting_creates_report_row_and_interaction`.
    - [ ] `posts_reports_count_is_incremented_on_report`.
    - [ ] `user_cannot_report_same_post_twice` (unique index).
    - [ ] `reported_post_disappears_from_reporter_feed`.

### 6.3 Retirada automática por reports threshold (US-015)
- [ ] **6.3.1** Threshold inicial: `config('recommendation.moderation.reports_threshold') = 5`.
- [ ] **6.3.2** Filtro duro no `CandidateGenerator`: `WHERE posts.reports_count < threshold`.
- [ ] **6.3.3** Post permanece na timeline do autor (não deletado; apenas filtrado do feed de outros).
- **Pest tests (`tests/Feature/Rec/ReportsThresholdTest.php`):**
    - [ ] `post_with_reports_above_threshold_is_excluded_from_feed`.
    - [ ] `post_below_threshold_still_appears`.
    - [ ] `threshold_is_configurable`.

### 6.4 Comando de reversão (US-022 complementar)
- [ ] **6.4.1** `rec:reverse-report {post_id}` — zera `reports_count` e marca `reports.resolved_at`.
- **Pest tests (`tests/Feature/Rec/ReverseReportCommandTest.php`):**
    - [ ] `command_zeros_reports_count`.
    - [ ] `command_sets_resolved_at_on_all_open_reports_for_post`.

---

