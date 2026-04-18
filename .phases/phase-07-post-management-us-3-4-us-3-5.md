## Phase 7 — Post Management (US-3.4, US-3.5)

### 7.1 Edit caption (US-3.4)
- [ ] `post.edit-caption` Livewire component (inline or modal) — only author sees action
- [ ] Policy `PostPolicy::update(User $user, Post $post)`
- [ ] Only `body` can change; media and type cannot
- **Pest tests (`tests/Feature/Posts/EditPostCaptionTest.php`):**
    - [ ] `author can edit their own post caption`
    - [ ] `non-author cannot edit someone else's post (403)`
    - [ ] `validation: new body required / max 2200`
    - [ ] `post_type and media are unchanged after edit`

### 7.2 Delete post (US-3.5)
- [ ] `post.delete` action (button + confirm modal) — only author
- [ ] Policy `PostPolicy::delete`
- [ ] Uses DB transaction; invokes `MediaUploadService::deleteForPost()` to remove files
- [ ] DB cascade removes likes, comments, post_media rows
- **Pest tests (`tests/Feature/Posts/DeletePostTest.php`) — `Storage::fake()`:**
    - [ ] `author can delete their own post`
    - [ ] `non-author cannot delete someone else's post (403)`
    - [ ] `deleting a post removes its media files from the disk`
    - [ ] `deleting a post cascades to likes and comments in the database`
    - [ ] `post disappears from the feed after deletion`

---

