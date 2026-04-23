## Phase 2 — Authentication (US-1.1, US-1.2, US-1.3)

### 2.1 Register page (US-1.1)
- [x] Full-page Livewire component `pages::auth.register`, mounted at `GET /register`
- [x] Uses `<x-ui.input>` / `<x-ui.button>` from Phase 1
- [x] Form Object `App\Livewire\Forms\RegisterForm` with `#[Validate]` rules (name required, email unique, password min 8 + confirmation)
- [x] Creates user, logs them in, redirects to `/` (feed)
- **Pest tests (`tests/Feature/Auth/RegisterTest.php`):**
    - [x] `register page is reachable` — `$this->get('/register')->assertOk()`
    - [x] `a visitor can register and is logged in` — Livewire test: set form fields, call `register`, assert redirect to feed and `auth()->check()` true
    - [x] `email must be unique` — seed existing user, assert validation error on `form.email`
    - [x] `password must meet minimum length` — short password triggers error
    - [x] `password confirmation must match` — mismatch triggers error
    - [x] `new account is immediately usable (no email verification)` — user created without `email_verified_at`, is able to post (covered by smoke test in Phase 5)

### 2.2 Login page (US-1.2)
- [x] Full-page Livewire component `pages::auth.login`, mounted at `GET /login`
- [x] Form Object `LoginForm` with email/password + optional `remember`
- [x] On success redirect to intended URL or `/`
- [x] Generic "invalid credentials" error (don't leak which field is wrong)
- **Pest tests (`tests/Feature/Auth/LoginTest.php`):**
    - [x] `login page is reachable`
    - [x] `a registered user can log in`
    - [x] `invalid credentials show a generic error`
    - [x] `remember me persists the session cookie`
    - [x] `authenticated user visiting /login is redirected to /`

### 2.3 Logout (US-1.3)
- [x] Logout endpoint `POST /logout` invalidating session
- [x] Triggered from user menu in authenticated layout
- **Pest tests (`tests/Feature/Auth/LogoutTest.php`):**
    - [x] `an authenticated user can log out` — asserts `auth()->check()` becomes false and redirect to `/login`
    - [x] `logout requires POST` — `GET /logout` returns 405

### 2.4 Route protection
- [x] Feed route and all post/like/comment routes sit behind `auth` middleware
- [x] Guest hitting an auth-only route is redirected to `/login`
- **Pest tests (`tests/Feature/Auth/RouteProtectionTest.php`):**
    - [x] `guest is redirected from / to /login`
    - [x] `guest is redirected from any post-action endpoint to /login`

---

