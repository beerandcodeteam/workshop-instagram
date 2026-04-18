## Phase 3 — Domain Schema & Models

### 3.1 `post_types` lookup table
- [ ] Migration creating `post_types` (id, name, slug unique, is_active, timestamps)
- [ ] `App\Models\PostType` model with `$fillable`, `posts()` HasMany relation
- [ ] `PostTypeSeeder` seeding three rows: `text`, `image`, `video`
- [ ] `DatabaseSeeder` calls `PostTypeSeeder`
- **Pest tests (`tests/Feature/Database/PostTypeSeederTest.php`):**
    - [ ] `post_types table is seeded with text, image and video`
    - [ ] `post_type slugs are unique`

### 3.2 `posts` table + Post model
- [ ] Migration per `.docs/database-schema.md` (user_id FK, post_type_id FK, nullable `body text`, timestamps, indexes on `created_at`, `user_id`, `post_type_id`)
- [ ] `App\Models\Post` with `$fillable`, relations: `author()` BelongsTo User, `type()` BelongsTo PostType, `media()` HasMany PostMedia (ordered by `sort_order`), `likes()` HasMany Like, `comments()` HasMany Comment
- [ ] `PostFactory` with states `text()`, `image($count = 1)`, `video()`
- **Pest tests (`tests/Feature/Models/PostTest.php`):**
    - [ ] `a post belongs to an author`
    - [ ] `a post belongs to a post type`
    - [ ] `post factory text state creates a post with body and no media`
    - [ ] `post factory image state creates a post with N media rows in order`
    - [ ] `post factory video state creates a post with exactly one media row`

### 3.3 `post_media` table + PostMedia model
- [ ] Migration (post_id FK cascade, file_path varchar 2048, sort_order integer default 0, unique `(post_id, sort_order)`, timestamps)
- [ ] `App\Models\PostMedia` with `$fillable`, `post()` BelongsTo
- [ ] `PostMediaFactory`
- **Pest tests (`tests/Feature/Models/PostMediaTest.php`):**
    - [ ] `post_media belongs to a post`
    - [ ] `unique sort_order per post is enforced` (inserting duplicate raises `QueryException`)
    - [ ] `deleting a post cascades to post_media`

### 3.4 `likes` table + Like model
- [ ] Migration (user_id FK, post_id FK cascade, unique `(user_id, post_id)`, timestamps)
- [ ] `App\Models\Like` with `$fillable`, `user()`, `post()`
- [ ] `LikeFactory`
- **Pest tests (`tests/Feature/Models/LikeTest.php`):**
    - [ ] `a like belongs to a user and a post`
    - [ ] `a user cannot like the same post twice` (unique constraint)
    - [ ] `deleting a post cascades to likes`

### 3.5 `comments` table + Comment model
- [ ] Migration (user_id FK, post_id FK cascade, body text not null, timestamps, index `(post_id, created_at)`)
- [ ] `App\Models\Comment` with `$fillable`, `author()`, `post()`
- [ ] `CommentFactory`
- **Pest tests (`tests/Feature/Models/CommentTest.php`):**
    - [ ] `a comment belongs to an author and a post`
    - [ ] `deleting a post cascades to comments`

### 3.6 Inverse relations on User
- [ ] Add `posts()`, `likes()`, `comments()` HasMany relations to `User`
- **Pest tests (`tests/Feature/Models/UserRelationsTest.php`):**
    - [ ] `user has many posts / likes / comments`

---

