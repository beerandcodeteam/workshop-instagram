## Phase 4 — Captura de dwell time

**Histórias:** US-008.

### 4.1 Frontend: IntersectionObserver + beacon
- [ ] **4.1.1** Componente Livewire `post.card` emite, via Alpine, evento `view-event` quando o card entra/sai do viewport (≥50%).
- [ ] **4.1.2** Buffer client-side agrega eventos e envia em batch:
  - A cada 15s (intervalo) OU
  - No `beforeunload` via `navigator.sendBeacon`.
- [ ] **4.1.3** Endpoint `POST /api/rec/view-events` (aceita JSON array, auth required). Cria N `PostInteraction(kind=view OR skip_fast, duration_ms=...)` conforme curva §5.4 do overview.
- **Pest tests (`tests/Feature/Rec/ViewEventsApiTest.php`):**
    - [ ] `authenticated_user_can_post_view_events` (200, rows criadas).
    - [ ] `guest_receives_401`.
    - [ ] `batch_with_mixed_durations_creates_correct_kinds` — 500ms→skip_fast, 5s→view, 35s→view capped.
    - [ ] `neutral_dwell_between_1_and_3s_is_not_recorded`.
    - [ ] `duration_ms_is_persisted`.
    - [ ] `view_weight_follows_documented_curve` — parametrizar com dataset.

### 4.2 `AggregateViewSignalsJob`
- [ ] **4.2.1** Job em `Schedule::command(...)->everyTenMinutes()` para usuários com atividade view nos últimos 10min.
- [ ] **4.2.2** Recalcula short-term do user se `Σ new_weight > threshold_delta` (evita thrashing).
- **Pest tests (`tests/Feature/Rec/AggregateViewSignalsJobTest.php`):**
    - [ ] `job_triggers_st_refresh_for_users_with_new_view_events`.
    - [ ] `job_is_noop_if_delta_below_threshold`.

---

