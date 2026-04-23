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

