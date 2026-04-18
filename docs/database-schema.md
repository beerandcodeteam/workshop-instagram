# Database Schema

## Overview

This is the suggested database schema for **Workshop Instagram**, derived from `.docs/project-description.md` and `.docs/user-stories.md`. It follows Laravel 13 conventions (bigint auto-increment PKs, `timestamps()` columns, plural snake_case table names, singular FK columns such as `user_id`).

**Design choices driven by the guidelines:**

- **No enum/string enum columns.** The set of supported post types (`text`, `image`, `video`) is modeled as a lookup table `post_types` referenced by `posts.post_type_id`.
- **File storage.** Media file paths are stored as string columns (`post_media.file_path`) pointing to the configured filesystem disk (MinIO in local/dev, S3 in production). Because a post can have multiple files (image carousel), a dedicated `post_media` table holds 1..N rows per post.
- **Single `body` column on `posts`.** It holds the text body (for text posts) or the optional caption (for image/video posts). Required-ness is enforced at the application layer based on `post_type_id`.
- **Like uniqueness** is enforced by a composite unique index on `(user_id, post_id)` so a user can like a given post at most once.
- **Cascade deletes.** Deleting a post cascades to its `post_media`, `likes`, and `comments` (satisfies US-3.5).
- **No admin role, no email verification in the MVP.** The standard Laravel `email_verified_at` column is kept nullable for framework compatibility but is not enforced.

## Entity Summary

| Table         | Purpose                                                           |
|---------------|-------------------------------------------------------------------|
| `users`       | Authenticated application user (single role in MVP)               |
| `post_types`  | Lookup table: `text`, `image`, `video`                            |
| `posts`       | A user's post, typed via `post_type_id`                           |
| `post_media`  | Image(s) or video file attached to a post                         |
| `likes`       | A user's like on a post (toggle; at most one per user per post)   |
| `comments`    | A user's flat comment on a post                                   |

## Schema (DBML)

```dbml
// Workshop Instagram — database schema
// DBML reference: https://dbml.dbdiagram.io/docs

Project workshop_instagram {
  database_type: 'MySQL'
  Note: '''
  Simplified Instagram clone: auth, global feed, posts (text / image / video),
  likes and comments. Base for a workshop on embeddings and recommendations —
  the recommendation layer is NOT modeled here and will be added manually.
  '''
}

// ============================================================
// Lookup tables
// ============================================================

Table post_types {
  id bigint [pk, increment]
  name varchar(50) [not null, unique, note: 'Human-readable label, e.g. "Text", "Image", "Video"']
  slug varchar(50) [not null, unique, note: 'Machine key used in code: "text", "image", "video"']
  is_active boolean [not null, default: true]
  created_at timestamp [null]
  updated_at timestamp [null]

  Note: 'Seeded by a database seeder with the three supported post types.'
}

// ============================================================
// Core tables
// ============================================================

Table users {
  id bigint [pk, increment]
  name varchar(255) [not null]
  email varchar(255) [not null, unique]
  email_verified_at timestamp [null, note: 'Not enforced in MVP; kept for Laravel framework compatibility']
  password varchar(255) [not null]
  remember_token varchar(100) [null]
  created_at timestamp [null]
  updated_at timestamp [null]

  Note: 'Authenticated user. Single application role — no admin in MVP.'
}

Table posts {
  id bigint [pk, increment]
  user_id bigint [not null, note: 'FK to users.id — author of the post']
  post_type_id bigint [not null, note: 'FK to post_types.id']
  body text [null, note: '''
    Text body (for text posts) or caption (for image/video posts).
    Max 2,200 characters — enforced at the application layer.
    Required for text posts, optional for image/video posts (validation in app).
  ''']
  created_at timestamp [null]
  updated_at timestamp [null]

  indexes {
    created_at [name: 'posts_created_at_index', note: 'Feed ordering: newest first']
    user_id [name: 'posts_user_id_index']
    post_type_id [name: 'posts_post_type_id_index']
  }

  Note: 'A post belongs to exactly one user and one post_type. Media is stored in post_media.'
}

Table post_media {
  id bigint [pk, increment]
  post_id bigint [not null, note: 'FK to posts.id — ON DELETE CASCADE']
  file_path varchar(2048) [not null, note: 'Path on the configured filesystem disk (MinIO in local/dev, S3 in prod)']
  sort_order integer [not null, default: 0, note: 'Carousel order (0-based). Always 0 for single-image or video posts.']
  created_at timestamp [null]
  updated_at timestamp [null]

  indexes {
    (post_id, sort_order) [unique, name: 'post_media_post_id_sort_order_unique']
  }

  Note: '''
  Media attached to a post.
  - Image posts: 1–10 rows (max carousel size enforced at app layer).
  - Video posts: exactly 1 row (100 MB / 60 s limit enforced at app layer).
  - Text posts: no rows.
  '''
}

Table likes {
  id bigint [pk, increment]
  user_id bigint [not null, note: 'FK to users.id']
  post_id bigint [not null, note: 'FK to posts.id — ON DELETE CASCADE']
  created_at timestamp [null]
  updated_at timestamp [null]

  indexes {
    (user_id, post_id) [unique, name: 'likes_user_id_post_id_unique', note: 'One like per user per post']
    post_id [name: 'likes_post_id_index']
  }

  Note: 'Toggle-style like. Unliking deletes the row.'
}

Table comments {
  id bigint [pk, increment]
  user_id bigint [not null, note: 'FK to users.id']
  post_id bigint [not null, note: 'FK to posts.id — ON DELETE CASCADE']
  body text [not null, note: '1–2,200 characters, enforced at app layer']
  created_at timestamp [null]
  updated_at timestamp [null]

  indexes {
    (post_id, created_at) [name: 'comments_post_id_created_at_index', note: 'Chronological listing per post (oldest first)']
    user_id [name: 'comments_user_id_index']
  }

  Note: 'Flat comments — no nesting, no editing, no reactions on comments.'
}

// ============================================================
// Relationships
// ============================================================

Ref: posts.user_id > users.id                  // A user has many posts
Ref: posts.post_type_id > post_types.id        // A post has one type
Ref: post_media.post_id > posts.id             // A post has many media files (cascade on delete)
Ref: likes.user_id > users.id                  // A user has many likes
Ref: likes.post_id > posts.id                  // A post has many likes (cascade on delete)
Ref: comments.user_id > users.id               // A user has many comments
Ref: comments.post_id > posts.id               // A post has many comments (cascade on delete)
```

## Traceability to User Stories

| Story        | Tables / columns involved                                                                 |
|--------------|-------------------------------------------------------------------------------------------|
| US-1.1–1.3   | `users`                                                                                   |
| US-2.1, 2.2  | `posts` (`created_at` index), `post_types`, `post_media`, `likes` (count), `comments` (count) |
| US-3.1       | `posts` (`post_type_id` = text, `body` required by app)                                   |
| US-3.2       | `posts` + 1..10 `post_media` rows with `sort_order`                                       |
| US-3.3       | `posts` + exactly 1 `post_media` row                                                      |
| US-3.4       | `posts.body` update                                                                       |
| US-3.5       | `posts` delete → cascades to `post_media`, `likes`, `comments`                            |
| US-4.1       | `likes` insert/delete; unique `(user_id, post_id)` enforces one-per-user                  |
| US-5.1       | `comments` insert; index on `(post_id, created_at)` for chronological order               |
| US-5.2       | `comments` delete by author                                                               |

## Not Modeled (Intentional)

- **Follows / followers / profiles** — out of scope per project description.
- **Admin / roles / permissions** — no admin in MVP.
- **Password reset tokens, email verification tokens, sessions** — Laravel ships these in default migrations; include them unchanged when scaffolding but they are not business-domain tables.
- **Embeddings / vector columns / recommendation tables** — the embedding layer will be added manually by the instructor during the workshop and is deliberately excluded from this baseline schema.
