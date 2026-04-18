## Phase 10 — Cross-cutting quality gates

### 10.1 Architecture tests
- [ ] `tests/Feature/ArchTest.php` (Pest arch):
    - [ ] `app/Services` classes are not accessed from Blade or Routes
    - [ ] Models do not use the `Auth` facade
    - [ ] Controllers (if any) only return redirects/views — no business logic

### 10.2 Smoke test
- [ ] `tests/Feature/SmokeTest.php`:
    - [ ] `authenticated smoke path: register → create text post → like own post → comment → delete post` — end-to-end happy path completes without errors

### 10.3 Tooling
- [ ] `vendor/bin/sail bin pint --format agent` clean
- [ ] `vendor/bin/sail artisan test --compact` green
- [ ] `vendor/bin/sail npm run build` clean

---

## Out of Scope for the Base App

Explicitly deferred (and intentionally **not** represented in this plan):

- Embeddings / vector storage / personalized feed ranking — implemented manually by the instructor during the workshop.
- Follow / profile / DM / stories / admin dashboard — declared out of scope in `.docs/project-description.md`.
- Password reset and email verification flows — not prioritized for the workshop MVP.

---

## Status Summary

| Phase | Title | Status |
|-------|-------|--------|
| 0     | Project baseline | Complete |
| 1     | Frontend foundations | Pending |
| 2     | Authentication | Pending |
| 3     | Domain schema & models | Pending |
| 4     | File storage (MinIO/S3) | Pending |
| 5     | Feed | Pending |
| 6     | Post creation | Pending |
| 7     | Post management | Pending |
| 8     | Likes | Pending |
| 9     | Comments | Pending |
| 10    | Cross-cutting quality gates | Pending |
