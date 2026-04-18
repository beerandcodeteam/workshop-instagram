# User Stories

## Overview

This document contains user stories for **Workshop Instagram**, a simplified Instagram clone built as the foundation for a teaching workshop on embeddings and recommendation systems. The base application covers authentication, a global feed, post creation (text, image/carousel, video), likes, and comments. Any embedding/recommendation functionality is **out of scope** for these user stories — that layer will be implemented manually by the workshop instructor during class.

**User Types:**
- **Visitor** — Unauthenticated user (can only access the auth pages)
- **User** — Authenticated user (the only authenticated role; creates posts, likes, comments)

**Global rules referenced by multiple stories:**
- Carousel posts allow a maximum of **10 images**.
- Videos are limited to **100 MB** and **60 seconds** in duration.
- Text post body and captions are limited to **2,200 characters**.
- No email verification is required — users can post, like, and comment immediately after registration.
- There is no admin role in the MVP.

---

## 1. Authentication & Registration

### US-1.1: User Registration
**As a** Visitor
**I want to** register with my name, email, and password
**So that** I can create posts and interact with the feed

**Acceptance Criteria:**
- [ ] Registration form collects: name, email, password, password confirmation
- [ ] Email must be unique
- [ ] Password must meet minimum security requirements (8+ characters)
- [ ] No email verification step
- [ ] User is automatically logged in after successful registration
- [ ] User is redirected to the feed

**Expected Result:** A new account is created and the user lands on the feed ready to interact.

---

### US-1.2: User Login
**As a** Registered User
**I want to** log in with my email and password
**So that** I can access the feed and post content

**Acceptance Criteria:**
- [ ] Login form collects: email, password
- [ ] Invalid credentials display a generic error message
- [ ] “Remember me” option persists the session
- [ ] Successful login redirects to the feed
- [ ] Unauthenticated access to feed/post routes redirects to login

**Expected Result:** User is authenticated and redirected to the feed.

---

### US-1.3: User Logout
**As an** Authenticated User
**I want to** log out of the application
**So that** my session is terminated

**Acceptance Criteria:**
- [ ] Logout action is available from the main navigation
- [ ] Session is invalidated on logout
- [ ] User is redirected to the login page

**Expected Result:** User is signed out and cannot access authenticated routes without logging in again.

---

## 2. Feed

### US-2.1: View Global Feed
**As an** Authenticated User
**I want to** view a feed of all posts from all users
**So that** I can see what the community is sharing

**Acceptance Criteria:**
- [ ] Feed is the authenticated home page
- [ ] Posts are listed in reverse-chronological order (newest first)
- [ ] Feed includes posts from **all** users (no follow filtering)
- [ ] Each feed item displays:
    - Author name
    - Post created-at timestamp
    - Post content (rendered according to type)
    - Like count and the current user's like state
    - Comment count
- [ ] Feed loads more posts via **infinite scroll** as the user scrolls down
- [ ] Feed gracefully handles the empty state (no posts yet)

**Expected Result:** User can scroll through all community posts in chronological order.

---

### US-2.2: Render Post by Type
**As an** Authenticated User
**I want** each post in the feed to be rendered according to its content type
**So that** I can consume the content naturally

**Acceptance Criteria:**
- [ ] **Text post** — renders the text body (up to 2,200 characters)
- [ ] **Image post (single)** — renders a single image
- [ ] **Image post (carousel)** — renders a swipeable carousel with navigation between images (up to 10)
- [ ] **Video post** — renders an inline video player with standard controls
- [ ] All post types display their optional caption beneath the media (if present)
- [ ] Media loads from the configured filesystem disk (MinIO locally, S3 in production)

**Expected Result:** Each post is displayed correctly according to its type, with working navigation for carousels and playback controls for videos.

---

## 3. Post Creation & Management

### US-3.1: Create Text Post
**As an** Authenticated User
**I want to** publish a text-only post
**So that** I can share written thoughts with the community

**Acceptance Criteria:**
- [ ] Create-post flow is accessible from the feed
- [ ] User selects post type = Text
- [ ] Text body is required (1–2,200 characters)
- [ ] On submit, the post is persisted with the current user as author
- [ ] The new post appears at the top of the feed immediately
- [ ] Validation errors are displayed inline without losing input

**Expected Result:** The new text post is published and visible at the top of the feed.

---

### US-3.2: Create Image Post
**As an** Authenticated User
**I want to** publish an image post with one or multiple images
**So that** I can share photos with the community

**Acceptance Criteria:**
- [ ] User selects post type = Image
- [ ] User uploads between 1 and 10 images
- [ ] Supported formats: JPG, PNG, WEBP
- [ ] A single image renders as a standalone image; 2+ images render as a carousel
- [ ] Optional caption (up to 2,200 characters)
- [ ] Images are stored on the configured filesystem disk (MinIO locally, S3 in production)
- [ ] On submit, the post appears at the top of the feed immediately
- [ ] Upload progress is visible to the user during save

**Expected Result:** The image (or carousel) post is published and visible at the top of the feed.

---

### US-3.3: Create Video Post
**As an** Authenticated User
**I want to** publish a video post
**So that** I can share video content with the community

**Acceptance Criteria:**
- [ ] User selects post type = Video
- [ ] One video file required
- [ ] Supported formats: MP4, MOV, WEBM
- [ ] Maximum file size: 100 MB
- [ ] Maximum duration: 60 seconds
- [ ] Optional caption (up to 2,200 characters)
- [ ] Video is stored on the configured filesystem disk (MinIO locally, S3 in production)
- [ ] On submit, the post appears at the top of the feed immediately
- [ ] Validation errors (size/duration/format) are clearly surfaced to the user

**Expected Result:** The video post is published and playable in the feed.

---

### US-3.4: Edit Own Post Caption
**As the** Author of a post
**I want to** edit the caption/text of my post after publishing
**So that** I can fix typos or update the description

**Acceptance Criteria:**
- [ ] “Edit” action is only visible to the post's author
- [ ] User can edit:
    - Text body (for text posts)
    - Caption (for image and video posts)
- [ ] Post type and media cannot be changed after creation
- [ ] Same length validation as on creation (up to 2,200 characters)
- [ ] Updated content is reflected in the feed immediately

**Expected Result:** The post's text/caption is updated while media and authorship remain unchanged.

---

### US-3.5: Delete Own Post
**As the** Author of a post
**I want to** delete my post
**So that** I can remove content I no longer want published

**Acceptance Criteria:**
- [ ] “Delete” action is only visible to the post's author
- [ ] A confirmation prompt is shown before deletion
- [ ] Deletion removes the post, all its likes, and all its comments
- [ ] Stored media files (images/video) are removed from the filesystem disk
- [ ] The post disappears from the feed immediately

**Expected Result:** The post and all its related data are removed permanently.

---

## 4. Likes

### US-4.1: Like and Unlike a Post
**As an** Authenticated User
**I want to** like or unlike any post in the feed
**So that** I can express appreciation for content

**Acceptance Criteria:**
- [ ] Like button is visible on every post in the feed
- [ ] Clicking the button toggles the like state for the current user
- [ ] A user can like a given post **at most once** (enforced at the database level)
- [ ] The like count updates reactively without a full page reload
- [ ] The button visually reflects the current user's like state
- [ ] Liking is available on all post types (text, image, video)

**Expected Result:** The user's like state on the post is toggled and the like count reflects it instantly.

---

## 5. Comments

### US-5.1: Comment on a Post
**As an** Authenticated User
**I want to** comment on any post in the feed
**So that** I can engage in conversation about the content

**Acceptance Criteria:**
- [ ] Comment input is available on every post
- [ ] Comment body is required (1–2,200 characters)
- [ ] Submitting a comment adds it to the post immediately (no page reload)
- [ ] The post's comment count updates reactively
- [ ] Comments are listed beneath the post in **chronological order** (oldest first)
- [ ] Each comment displays: author name, created-at timestamp, body
- [ ] No nested replies, no comment editing, no reactions on comments

**Expected Result:** The comment is published and visible to everyone viewing the post.

---

### US-5.2: Delete Own Comment
**As the** Author of a comment
**I want to** delete my comment
**So that** I can remove something I no longer want to say

**Acceptance Criteria:**
- [ ] “Delete” action is only visible to the comment's author
- [ ] A confirmation prompt is shown before deletion
- [ ] Comment is removed from the post's comment list immediately
- [ ] The post's comment count decreases accordingly

**Expected Result:** The comment is permanently removed from the post.

---

## Out of Scope (Reference Only)

The following are **explicitly not** covered by any user story in this document:

- Following users, followers/following feed filtering
- User profiles or profile pages
- Direct messages, stories, reels
- Admin role, moderation, platform-wide dashboards
- Email verification, password reset (not prioritized for the workshop MVP)
- Personalized / algorithmic feed ranking — the embedding-based recommendation layer will be implemented manually by the instructor during the workshop and is not part of the base application's user stories

---

## Appendix: User Story Status

| ID     | Story                          | Priority | Status  |
|--------|--------------------------------|----------|---------|
| US-1.1 | User Registration              | High     | Pending |
| US-1.2 | User Login                     | High     | Pending |
| US-1.3 | User Logout                    | High     | Pending |
| US-2.1 | View Global Feed               | High     | Pending |
| US-2.2 | Render Post by Type            | High     | Pending |
| US-3.1 | Create Text Post               | High     | Pending |
| US-3.2 | Create Image Post              | High     | Pending |
| US-3.3 | Create Video Post              | High     | Pending |
| US-3.4 | Edit Own Post Caption          | Medium   | Pending |
| US-3.5 | Delete Own Post                | Medium   | Pending |
| US-4.1 | Like and Unlike a Post         | High     | Pending |
| US-5.1 | Comment on a Post              | High     | Pending |
| US-5.2 | Delete Own Comment             | Medium   | Pending |
