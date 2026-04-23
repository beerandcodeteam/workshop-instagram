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

