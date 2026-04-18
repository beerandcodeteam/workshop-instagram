## Phase 5 — Feed (US-2.1, US-2.2)

### 5.1 Feed full-page component
- [ ] Livewire full-page component `pages::feed.index`, mounted at `GET /` behind `auth`
- [ ] Uses `layouts/app.blade.php`
- [ ] Loads posts in reverse-chronological order, eager-loads `author`, `type`, `media`, `likes:id,post_id,user_id`, `withCount('likes','comments')`
- [ ] Empty state when no posts exist
- **Pest tests (`tests/Feature/Feed/FeedPageTest.php`):**
    - [ ] `feed route renders for an authenticated user`
    - [ ] `feed is ordered newest first`
    - [ ] `feed shows the empty state when there are no posts`
    - [ ] `feed exposes each post's like count and comment count`

### 5.2 Post card component (render-by-type)
- [ ] Livewire component `post.card` (receives `Post $post`), renders:
    - Author name + timestamp
    - Text body, single image, carousel, or video player depending on `type->slug`
    - Like button (state placeholder; wired in Phase 8)
    - Comments toggle (wired in Phase 9)
- [ ] Carousel uses Alpine for prev/next; shows active index indicator
- [ ] Video element uses native `<video controls>`
- **Pest tests (`tests/Feature/Feed/PostCardTest.php`):**
    - [ ] `text post renders its body`
    - [ ] `single-image post renders one <img>`
    - [ ] `carousel post renders all images in sort_order and no duplicates`
    - [ ] `video post renders a <video> tag with the stored source`
    - [ ] `caption is rendered for image and video posts when present`

### 5.3 Infinite scroll
- [ ] Paginated loading (page size 10) with a Livewire-driven "load more" trigger observed via Intersection (Alpine) that calls a `loadMore()` action
- [ ] Component exposes `hasMorePages` state
- **Pest tests (`tests/Feature/Feed/InfiniteScrollTest.php`):**
    - [ ] `initial render shows at most the page size of posts`
    - [ ] `calling loadMore appends the next page`
    - [ ] `hasMorePages becomes false after the last page is loaded`

---

