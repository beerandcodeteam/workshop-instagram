## Phase 8 — Likes (US-4.1)

### 8.1 Like toggle
- [ ] `post.like-button` Livewire component — receives `Post $post`, reads current user's like state, exposes `toggle()` action
- [ ] Toggle is idempotent: creating uses `firstOrCreate`; unlike uses `delete`
- [ ] Reactive update of like count on the card without page reload
- **Pest tests (`tests/Feature/Likes/LikePostTest.php`):**
    - [ ] `guest cannot like a post (redirect to login)`
    - [ ] `authenticated user can like a post`
    - [ ] `a second click removes the like`
    - [ ] `liking twice in the same session creates only one row`
    - [ ] `like count reflects total likes across users`
    - [ ] `like button shows the correct state for the current user on feed load`

---

