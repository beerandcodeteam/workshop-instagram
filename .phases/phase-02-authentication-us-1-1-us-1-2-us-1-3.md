## Phase 2 — Authentication (US-1.1, US-1.2, US-1.3)

### 2.1 Register page (US-1.1)
- [ ] Full-page Livewire component `pages::auth.register`, mounted at `GET /register`
- [ ] Uses `<x-ui.input>` / `<x-ui.button>` from Phase 1
- [ ] Form Object `App\Livewire\Forms\RegisterForm` with `#[Validate]` rules (name required, email unique, password min 8 + confirmation)
- [ ] Creates user, logs them in, redirects to `/` (feed)
- **Pest tests (`tests/Feature/Auth/RegisterTest.php`):**
    - [ ] `register page is reachable` — `$this->get('/register')->assertOk()`
    - [ ] `a visitor can register and is logged in` — Livewire test: set form fields, call `register`, assert redirect to feed and `auth()->check()` true
    - [ ] `email must be unique` — seed existing user, assert validation error on `form.email`
    - [ ] `password must meet minimum length` — short password triggers error
    - [ ] `password confirmation must match` — mismatch triggers error
    - [ ] `new account is immediately usable (no email verification)` — user created without `email_verified_at`, is able to post (covered by smoke test in Phase 5)

### 2.2 Login page (US-1.2)
- [ ] Full-page Livewire component `pages::auth.login`, mounted at `GET /login`
- [ ] Form Object `LoginForm` with email/password + optional `remember`
- [ ] On success redirect to intended URL or `/`
- [ ] Generic "invalid credentials" error (don't leak which field is wrong)
- **Pest tests (`tests/Feature/Auth/LoginTest.php`):**
    - [ ] `login page is reachable`
    - [ ] `a registered user can log in`
    - [ ] `invalid credentials show a generic error`
    - [ ] `remember me persists the session cookie`
    - [ ] `authenticated user visiting /login is redirected to /`

### 2.3 Logout (US-1.3)
- [ ] Logout endpoint `POST /logout` invalidating session
- [ ] Triggered from user menu in authenticated layout
- **Pest tests (`tests/Feature/Auth/LogoutTest.php`):**
    - [ ] `an authenticated user can log out` — asserts `auth()->check()` becomes false and redirect to `/login`
    - [ ] `logout requires POST` — `GET /logout` returns 405

### 2.4 Route protection
- [ ] Feed route and all post/like/comment routes sit behind `auth` middleware
- [ ] Guest hitting an auth-only route is redirected to `/login`
- **Pest tests (`tests/Feature/Auth/RouteProtectionTest.php`):**
    - [ ] `guest is redirected from / to /login`
    - [ ] `guest is redirected from any post-action endpoint to /login`

---

