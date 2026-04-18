## Phase 9 — Comments (US-5.1, US-5.2)

### 9.1 Add comment (US-5.1)
- [ ] `post.comments` Livewire component — receives `Post $post`, shows list + inline form
- [ ] Form Object `CommentForm` — `body` required, max 2200
- [ ] On submit, comment appended to the list; post's comment count updates
- [ ] Comments listed in chronological order (`created_at ASC`)
- **Pest tests (`tests/Feature/Comments/AddCommentTest.php`):**
    - [ ] `guest cannot comment (redirect to login)`
    - [ ] `authenticated user can add a comment`
    - [ ] `body is required`
    - [ ] `body max length is 2200`
    - [ ] `comments are listed oldest-first`
    - [ ] `comment count on the post reflects the number of comments`

### 9.2 Delete own comment (US-5.2)
- [ ] Delete button visible only to the comment's author
- [ ] Policy `CommentPolicy::delete`
- [ ] Deletes the row and decrements comment count reactively
- **Pest tests (`tests/Feature/Comments/DeleteCommentTest.php`):**
    - [ ] `author can delete their own comment`
    - [ ] `non-author cannot delete someone else's comment (403)`
    - [ ] `comment count decreases after deletion`

---

