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

