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

