## Phase 1 — Log append-only de interações (fundação dos sinais)

**Histórias:** US-026, US-010 (parcial).
**Pré-requisito:** Phase 0 completa.

### 1.1 Tabela `post_interactions`
- [ ] **1.1.1** Migration criando `post_interactions` conforme §5 do schema doc (indices incluídos).
- [ ] **1.1.2** Model `App\Models\PostInteraction` com `$fillable`, relações `user()`, `post()`, `type()`.
- [ ] **1.1.3** Factory `PostInteractionFactory` com states `like()`, `comment()`, `view()`, `hide()`, `report()`.
- **Pest tests (`tests/Feature/Rec/PostInteractionTest.php`):**
    - [ ] `post_interactions_table_has_expected_indexes` — usa `DB::select('SELECT indexname FROM pg_indexes ...')` para confirmar os 5 indexes.
    - [ ] `a_post_interaction_belongs_to_user_post_and_type` (relações).
    - [ ] `factory_states_produce_valid_interactions` (1 assertion por state).

### 1.2 `LikeObserver` dual-write (US-026, US-010)
- [ ] **1.2.1** Estender `LikeObserver::created` para também criar `PostInteraction(kind=like, weight=default_weight)`.
- [ ] **1.2.2** Estender `LikeObserver::deleted` para criar `PostInteraction(kind=unlike, weight=-0.5)` em vez de apenas disparar recompute.
- **Pest tests (`tests/Feature/Rec/LikeDualWriteTest.php`):**
    - [ ] `liking_a_post_creates_a_post_interaction_row` — cria Like, assert `PostInteraction::where(kind=like)` existe.
    - [ ] `unliking_a_post_creates_an_unlike_interaction` — dois interactions (like + unlike) após toggle.

### 1.3 `CommentObserver` dual-write (US-026)
- [ ] **1.3.1** Criar `CommentObserver::created` → `PostInteraction(kind=comment, weight=1.5)`.
- [ ] **1.3.2** Registrar com `#[ObservedBy]` em `Comment`.
- **Pest tests (`tests/Feature/Rec/CommentDualWriteTest.php`):**
    - [ ] `commenting_creates_a_post_interaction_row`.
    - [ ] `deleting_a_comment_does_not_create_a_reverse_interaction` (comentário deletado é raro; não vira sinal negativo).

### 1.4 Share action + observer (US-026)
- [ ] **1.4.1** UI: botão "Compartilhar" em `post.card` (Livewire action; neste workshop basta copiar URL para clipboard).
- [ ] **1.4.2** Action grava `PostInteraction(kind=share, weight=2.0)`.
- **Pest tests (`tests/Feature/Rec/ShareActionTest.php`):**
    - [ ] `share_action_records_a_share_interaction`.
    - [ ] `share_requires_authentication` (guest redirects).

---

