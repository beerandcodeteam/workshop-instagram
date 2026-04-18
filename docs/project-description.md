# Project Description

## Overview

This project is a simplified Instagram clone built as the foundation for a teaching workshop focused on **embeddings and recommendation systems**. The application itself is intentionally minimal — it exists to provide a realistic content-driven domain (users, posts, likes, comments) on top of which the workshop will layer an embedding-based recommendation layer during the class.

The core app covers:

- User authentication with email and password (registration and login).
- A single global feed that lists all published posts in reverse-chronological order, loaded via infinite scroll.
- Post creation supporting three content types: **text**, **image** (single image or carousel of multiple images), and **video**.
- Interaction with posts through **likes** and **comments**.

Out of scope (explicitly not implemented):

- Following users, followers/following relationships.
- User profiles or profile pages.
- Direct messages, stories, reels, or any feature beyond the main feed.
- Personalized/algorithmic feed ranking (this will be added during the workshop as the embeddings/recommendation exercise — the base app ships with a simple chronological listing).

The goal is to keep the base application small and understandable so the workshop audience can focus on the embeddings and recommendation logic being added on top of it.

## Stack

- **Language / Runtime:** PHP 8.5
- **Framework:** Laravel 13
- **Frontend / UI:** Livewire 4 (class components, no Volt) with Tailwind CSS v4
- **AI / Embeddings:** `laravel/ai` (Laravel first-party AI SDK) — used during the workshop to generate embeddings and power recommendations
- **Testing:** Pest 4 (feature tests, Livewire component tests, browser tests where useful)
- **Local environment:** Laravel Sail (Docker)
- **File storage:**
  - **Local / development:** MinIO (S3-compatible) via Sail
  - **Production:** Amazon S3
- **Code style / tooling:** Laravel Pint, Laravel Boost MCP

## Core Workflows

### 1. Authentication

- A visitor can register with name, email, and password.
- A registered user can log in with email and password and log out.
- Unauthenticated users are redirected to the login page when trying to access the feed or any post action.

### 2. Viewing the feed

- After login, the user lands on the global feed (the app's home page).
- The feed lists all published posts from all users, newest first.
- The feed loads more posts via **infinite scroll** as the user scrolls down.
- Each feed item renders according to its post type:
  - **Text:** the post's text body.
  - **Image:** a single image, or a swipeable carousel when the post contains multiple images.
  - **Video:** an inline video player.
- Each feed item displays the author, created-at timestamp, like count, comment count, and the user's own like state.

### 3. Creating a post

- From the feed, an authenticated user can open the "create post" flow.
- The user chooses one of the three post types:
  - **Text post** — a text body.
  - **Image post** — uploads either one image or multiple images (carousel).
  - **Video post** — uploads a single video file.
- All post types accept an optional caption/description.
- Uploaded media is stored on the configured filesystem disk (MinIO in local/dev, S3 in production).
- On submit, the post is persisted and immediately appears at the top of the feed.

### 4. Liking a post

- An authenticated user can like any post in the feed.
- Liking is a toggle: clicking again removes the like.
- Each user can like a given post at most once.
- The like count on the post updates reactively.

### 5. Commenting on a post

- An authenticated user can add a text comment to any post.
- Comments are listed below the post in chronological order.
- The comment count on the post updates reactively.
- (No nested replies, no editing, no reactions on comments — keep it minimal.)

### 6. Recommendation layer (workshop scope — not part of the base app)

The base app ships with a simple chronological feed. During the workshop, we will extend it with an embeddings-powered recommendation system using `laravel/ai`:

- Generate embeddings for post content (text, captions, and optionally media-derived features).
- Generate embeddings representing user interests based on their interactions (likes, comments, views).
- Use vector similarity to rank feed items per user instead of pure chronological order.

This section is documented here only to frame *why* the base app looks the way it does — the recommendation implementation itself is out of scope for the base project and will be built live during the workshop.
