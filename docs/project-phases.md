# Project Phases

This document breaks the Workshop Instagram build into numbered phases and sub-phases so that each task can be referenced by its number (e.g. *"implement Phase 5.3"*) when handed off to an AI agent.

Tasks are derived from:
- `.docs/project-description.md`
- `.docs/user-stories.md`
- `.docs/database-schema.md`

Conventions used below:
- **[x]** — already implemented in the current codebase.
- **[ ]** — pending.
- Each non-frontend-setup task lists the **Pest feature tests** that must pass as its acceptance criteria.
- Tests live under `tests/Feature/` using Pest 4. Livewire component tests use `Livewire::test()`. All DB-touching tests use `RefreshDatabase`.

> **Note on `docs/design`**: a dedicated design folder does not exist in the repo. Phase 1 is based on Instagram's public visual identity (near-white background, near-black text, an Instagram-gradient accent for the primary action color, neutral grays for borders and muted text). Adjust tokens later if a formal design doc is added.

---

## Phase 0 — Project Baseline (pre-existing Laravel skeleton)

These items ship with the current repository and do not require new work.

- [x] **0.1** Laravel 13 skeleton installed
- [x] **0.2** Laravel Sail (Docker) configured (`compose.yaml`)
- [x] **0.3** Livewire 4 installed (`livewire/livewire ^4.2`)
- [x] **0.4** Pest 4 installed (`pestphp/pest ^4.6`, `pest-plugin-laravel`)
- [x] **0.5** Tailwind CSS 4 + Vite wired (`resources/css/app.css`, `vite.config.js`)
- [x] **0.6** `laravel/ai` SDK installed (for later workshop — not used by base app)
- [x] **0.7** Laravel Boost + Pint + Pail installed
- [x] **0.8** Default `users` / `password_reset_tokens` / `sessions` / `cache` / `jobs` migrations present
- [x] **0.9** `App\Models\User` model with `#[Fillable]`, `#[Hidden]`, `hashed` password cast
- [x] **0.10** `Database\Factories\UserFactory` present
- [x] **0.11** Empty `DatabaseSeeder` scaffolded

---

## Phase 1 — Frontend Foundations (no automated tests)

Goal: establish the visual language and reusable UI primitives. Manual browser verification only — no Pest tests required.

### 1.1 Design tokens (Tailwind theme)
- [x] Extend `resources/css/app.css` `@theme` block with design tokens inspired by Instagram identity:
    - Neutral scale (`--color-neutral-0` white, `--color-neutral-950` near-black, 100/200/300/500/700 mids)
    - Brand gradient stops (`--color-brand-from: #F58529`, `--color-brand-via: #DD2A7B`, `--color-brand-to: #8134AF`) for primary CTAs
    - Semantic tokens: `--color-bg`, `--color-surface`, `--color-border`, `--color-text`, `--color-text-muted`, `--color-danger`, `--color-success`
    - Font: keep `Instrument Sans` as default sans stack
    - Radius tokens: `--radius-sm`, `--radius-md`, `--radius-lg`, `--radius-full`

### 1.2 Base Blade components (folder: `resources/views/components/ui/`)
- [x] **1.2.1** `<x-ui.button>` — variants: `primary` (gradient fill), `secondary` (outline), `ghost`, `danger`; sizes `sm`, `md`, `lg`; loading state slot
- [x] **1.2.2** `<x-ui.input>` — label, error slot, hint slot, `wire:model` compatible
- [x] **1.2.3** `<x-ui.textarea>` — same API as input + character counter slot
- [x] **1.2.4** `<x-ui.select>` — options slot, matching input styling
- [x] **1.2.5** `<x-ui.checkbox>` — checked state, label
- [x] **1.2.6** `<x-ui.radio>` — labelled radio + `<x-ui.radio-group>` wrapper
- [x] **1.2.7** `<x-ui.modal>` — Alpine-powered, teleported to body, `wire:model` compatible open/close, keyboard-dismissable

### 1.3 Layouts (folder: `resources/views/layouts/`)
- [x] **1.3.1** `layouts/guest.blade.php` — centered card layout for login/register, subtle gradient background, app logomark
- [x] **1.3.2** `layouts/app.blade.php` — authenticated layout with:
    - Top bar: logomark, "Create post" button, user menu (avatar initial + logout)
    - Centered single-column content area (Instagram feed width ~470–630px)
    - Flash message slot

### 1.4 Asset pipeline verification
- [x] **1.4.1** Replace default `resources/views/welcome.blade.php` usage so `/` redirects appropriately for Phase 2
- [x] **1.4.2** Run `vendor/bin/sail npm run build` and confirm no Tailwind/Vite errors

---

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

## Phase 3 — Domain Schema & Models

### 3.1 `post_types` lookup table
- [x] Migration creating `post_types` (id, name, slug unique, is_active, timestamps)
- [x] `App\Models\PostType` model with `$fillable`, `posts()` HasMany relation
- [x] `PostTypeSeeder` seeding three rows: `text`, `image`, `video`
- [x] `DatabaseSeeder` calls `PostTypeSeeder`
- **Pest tests (`tests/Feature/Database/PostTypeSeederTest.php`):**
    - [x] `post_types table is seeded with text, image and video`
    - [x] `post_type slugs are unique`

### 3.2 `posts` table + Post model
- [x] Migration per `.docs/database-schema.md` (user_id FK, post_type_id FK, nullable `body text`, timestamps, indexes on `created_at`, `user_id`, `post_type_id`)
- [x] `App\Models\Post` with `$fillable`, relations: `author()` BelongsTo User, `type()` BelongsTo PostType, `media()` HasMany PostMedia (ordered by `sort_order`), `likes()` HasMany Like, `comments()` HasMany Comment
- [x] `PostFactory` with states `text()`, `image($count = 1)`, `video()`
- **Pest tests (`tests/Feature/Models/PostTest.php`):**
    - [x] `a post belongs to an author`
    - [x] `a post belongs to a post type`
    - [x] `post factory text state creates a post with body and no media`
    - [x] `post factory image state creates a post with N media rows in order`
    - [x] `post factory video state creates a post with exactly one media row`

### 3.3 `post_media` table + PostMedia model
- [x] Migration (post_id FK cascade, file_path varchar 2048, sort_order integer default 0, unique `(post_id, sort_order)`, timestamps)
- [x] `App\Models\PostMedia` with `$fillable`, `post()` BelongsTo
- [x] `PostMediaFactory`
- **Pest tests (`tests/Feature/Models/PostMediaTest.php`):**
    - [x] `post_media belongs to a post`
    - [x] `unique sort_order per post is enforced` (inserting duplicate raises `QueryException`)
    - [x] `deleting a post cascades to post_media`

### 3.4 `likes` table + Like model
- [x] Migration (user_id FK, post_id FK cascade, unique `(user_id, post_id)`, timestamps)
- [x] `App\Models\Like` with `$fillable`, `user()`, `post()`
- [x] `LikeFactory`
- **Pest tests (`tests/Feature/Models/LikeTest.php`):**
    - [x] `a like belongs to a user and a post`
    - [x] `a user cannot like the same post twice` (unique constraint)
    - [x] `deleting a post cascades to likes`

### 3.5 `comments` table + Comment model
- [x] Migration (user_id FK, post_id FK cascade, body text not null, timestamps, index `(post_id, created_at)`)
- [x] `App\Models\Comment` with `$fillable`, `author()`, `post()`
- [x] `CommentFactory`
- **Pest tests (`tests/Feature/Models/CommentTest.php`):**
    - [x] `a comment belongs to an author and a post`
    - [x] `deleting a post cascades to comments`

### 3.6 Inverse relations on User
- [x] Add `posts()`, `likes()`, `comments()` HasMany relations to `User`
- **Pest tests (`tests/Feature/Models/UserRelationsTest.php`):**
    - [x] `user has many posts / likes / comments`

---

## Phase 4 — File Storage (MinIO / S3)

### 4.1 Local MinIO service
- [x] Add MinIO service (and bucket-provisioning helper) to `compose.yaml`
- [x] Add env vars to `.env.example`: `FILESYSTEM_DISK=s3`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`, `AWS_ENDPOINT`, `AWS_USE_PATH_STYLE_ENDPOINT=true`, `AWS_URL`
- [x] Install `league/flysystem-aws-s3-v3` via Sail composer
- [x] Configure `config/filesystems.php` `s3` disk to consume the MinIO endpoint in local/dev

### 4.2 Media upload service
- [x] `App\Services\MediaUploadService` with methods:
    - `storeImage(UploadedFile $file, int $postId, int $sortOrder): string` — returns stored path
    - `storeVideo(UploadedFile $file, int $postId): string`
    - `delete(string $path): void` (and a `deleteForPost(Post $post)`)
- [x] Uses `Storage::disk(config('filesystems.default'))`
- **Pest tests (`tests/Feature/Services/MediaUploadServiceTest.php`) using `Storage::fake()`:**
    - [x] `storeImage writes to the configured disk and returns a path`
    - [x] `storeVideo writes to the configured disk and returns a path`
    - [x] `delete removes the file from the disk`

---

## Phase 5 — Feed (US-2.1, US-2.2)

### 5.1 Feed full-page component
- [x] Livewire full-page component `pages::feed.index`, mounted at `GET /` behind `auth`
- [x] Uses `layouts/app.blade.php`
- [x] Loads posts in reverse-chronological order, eager-loads `author`, `type`, `media`, `likes:id,post_id,user_id`, `withCount('likes','comments')`
- [x] Empty state when no posts exist
- **Pest tests (`tests/Feature/Feed/FeedPageTest.php`):**
    - [x] `feed route renders for an authenticated user`
    - [x] `feed is ordered newest first`
    - [x] `feed shows the empty state when there are no posts`
    - [x] `feed exposes each post's like count and comment count`

### 5.2 Post card component (render-by-type)
- [x] Livewire component `post.card` (receives `Post $post`), renders:
    - Author name + timestamp
    - Text body, single image, carousel, or video player depending on `type->slug`
    - Like button (state placeholder; wired in Phase 8)
    - Comments toggle (wired in Phase 9)
- [x] Carousel uses Alpine for prev/next; shows active index indicator
- [x] Video element uses native `<video controls>`
- **Pest tests (`tests/Feature/Feed/PostCardTest.php`):**
    - [x] `text post renders its body`
    - [x] `single-image post renders one <img>`
    - [x] `carousel post renders all images in sort_order and no duplicates`
    - [x] `video post renders a <video> tag with the stored source`
    - [x] `caption is rendered for image and video posts when present`

### 5.3 Infinite scroll
- [x] Paginated loading (page size 10) with a Livewire-driven "load more" trigger observed via Intersection (Alpine) that calls a `loadMore()` action
- [x] Component exposes `hasMorePages` state
- **Pest tests (`tests/Feature/Feed/InfiniteScrollTest.php`):**
    - [x] `initial render shows at most the page size of posts`
    - [x] `calling loadMore appends the next page`
    - [x] `hasMorePages becomes false after the last page is loaded`

---

## Phase 6 — Post Creation (US-3.1, US-3.2, US-3.3)

### 6.1 Create-post entry point
- [x] "Create post" button in authenticated layout opens a Livewire modal `post.create-modal` OR navigates to a full page (`GET /posts/create`) — pick modal for Instagram-like UX
- [x] Modal has a first step that selects post type (text / image / video), then shows the matching form step
- **Pest tests (`tests/Feature/Posts/CreatePostFlowTest.php`):**
    - [x] `guest cannot open the create-post flow`
    - [x] `authenticated user sees the type picker`

### 6.2 Text post (US-3.1)
- [x] Livewire Form Object `TextPostForm` — `body` required, max 2200
- [x] Persists post with `post_type_id = post_types(slug=text)` and the body
- [x] On success: close modal, emit `post.created` event, feed prepends the new post
- **Pest tests (`tests/Feature/Posts/CreateTextPostTest.php`):**
    - [x] `a user can publish a text post`
    - [x] `body is required`
    - [x] `body max length is 2200`
    - [x] `the post appears at the top of the feed after creation`

### 6.3 Image post (US-3.2)
- [x] Livewire Form Object `ImagePostForm` — `images` array (1–10), each `image` mimes `jpg,png,webp`, optional `caption` max 2200
- [x] Persists Post (type=image) + `post_media` rows with `sort_order` preserving upload order, using `MediaUploadService`
- **Pest tests (`tests/Feature/Posts/CreateImagePostTest.php`) — use `Storage::fake()`:**
    - [x] `a user can publish a single-image post`
    - [x] `a user can publish a carousel of up to 10 images`
    - [x] `more than 10 images is rejected`
    - [x] `zero images is rejected`
    - [x] `non-image files are rejected`
    - [x] `sort_order matches upload order`
    - [x] `caption max length is 2200`

### 6.4 Video post (US-3.3)
- [x] Livewire Form Object `VideoPostForm` — `video` required, mimes `mp4,mov,webm`, max 102400 KB (100 MB), optional `caption` max 2200
- [x] Persists Post (type=video) + single `post_media` row, via `MediaUploadService`
- [x] Duration check performed server-side (via `getID3` helper or similar) and fails if > 60s
- **Pest tests (`tests/Feature/Posts/CreateVideoPostTest.php`) — `Storage::fake()` and a stub duration probe:**
    - [x] `a user can publish a video post under 100 MB and 60 s`
    - [x] `video over 100 MB is rejected`
    - [x] `video over 60 seconds is rejected`
    - [x] `non-video files are rejected`
    - [x] `caption max length is 2200`

---

## Phase 7 — Post Management (US-3.4, US-3.5)

### 7.1 Edit caption (US-3.4)
- [x] `post.edit-caption` Livewire component (inline or modal) — only author sees action
- [x] Policy `PostPolicy::update(User $user, Post $post)`
- [x] Only `body` can change; media and type cannot
- **Pest tests (`tests/Feature/Posts/EditPostCaptionTest.php`):**
    - [x] `author can edit their own post caption`
    - [x] `non-author cannot edit someone else's post (403)`
    - [x] `validation: new body required / max 2200`
    - [x] `post_type and media are unchanged after edit`

### 7.2 Delete post (US-3.5)
- [x] `post.delete` action (button + confirm modal) — only author
- [x] Policy `PostPolicy::delete`
- [x] Uses DB transaction; invokes `MediaUploadService::deleteForPost()` to remove files
- [x] DB cascade removes likes, comments, post_media rows
- **Pest tests (`tests/Feature/Posts/DeletePostTest.php`) — `Storage::fake()`:**
    - [x] `author can delete their own post`
    - [x] `non-author cannot delete someone else's post (403)`
    - [x] `deleting a post removes its media files from the disk`
    - [x] `deleting a post cascades to likes and comments in the database`
    - [x] `post disappears from the feed after deletion`

---

## Phase 8 — Likes (US-4.1)

### 8.1 Like toggle
- [x] `post.like-button` Livewire component — receives `Post $post`, reads current user's like state, exposes `toggle()` action
- [x] Toggle is idempotent: creating uses `firstOrCreate`; unlike uses `delete`
- [x] Reactive update of like count on the card without page reload
- **Pest tests (`tests/Feature/Likes/LikePostTest.php`):**
    - [x] `guest cannot like a post (redirect to login)`
    - [x] `authenticated user can like a post`
    - [x] `a second click removes the like`
    - [x] `liking twice in the same session creates only one row`
    - [x] `like count reflects total likes across users`
    - [x] `like button shows the correct state for the current user on feed load`

---

## Phase 9 — Comments (US-5.1, US-5.2)

### 9.1 Add comment (US-5.1)
- [x] `post.comments` Livewire component — receives `Post $post`, shows list + inline form
- [x] Form Object `CommentForm` — `body` required, max 2200
- [x] On submit, comment appended to the list; post's comment count updates
- [x] Comments listed in chronological order (`created_at ASC`)
- **Pest tests (`tests/Feature/Comments/AddCommentTest.php`):**
    - [x] `guest cannot comment (redirect to login)`
    - [x] `authenticated user can add a comment`
    - [x] `body is required`
    - [x] `body max length is 2200`
    - [x] `comments are listed oldest-first`
    - [x] `comment count on the post reflects the number of comments`

### 9.2 Delete own comment (US-5.2)
- [x] Delete button visible only to the comment's author
- [x] Policy `CommentPolicy::delete`
- [x] Deletes the row and decrements comment count reactively
- **Pest tests (`tests/Feature/Comments/DeleteCommentTest.php`):**
    - [x] `author can delete their own comment`
    - [x] `non-author cannot delete someone else's comment (403)`
    - [x] `comment count decreases after deletion`

---

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
| 1     | Frontend foundations | Complete |
| 2     | Authentication | Complete |
| 3     | Domain schema & models | Complete |
| 4     | File storage (MinIO/S3) | Complete |
| 5     | Feed | Complete |
| 6     | Post creation | Complete |
| 7     | Post management | Complete |
| 8     | Likes | Complete |
| 9     | Comments | Complete |
| 10    | Cross-cutting quality gates | Pending |
