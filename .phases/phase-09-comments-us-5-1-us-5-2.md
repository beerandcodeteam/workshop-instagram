## Phase 9 — Comments (US-5.1, US-5.2)

### 9.1 Add comment (US-5.1)
- [x] `post.comments` Livewire component — receives `Post $post`, shows list + inline form
- [x] Form Object `CommentForm` — `body` required, max 2200
- [x] On submit, comment appended to the list; post's comment count updates
- [x] Comments listed in chronological order (`created_at ASC`)
- **Pest tests (`tests/Feature/Comments/AddCommentTest.php`):**
    - [x] `guest cannot comment (redirect to login)`
    - [x] `authenticated user can add a comment`
    - [x] `body is required`
    - [x] `body max length is 2200`
    - [x] `comments are listed oldest-first`
    - [x] `comment count on the post reflects the number of comments`

### 9.2 Delete own comment (US-5.2)
- [x] Delete button visible only to the comment's author
- [x] Policy `CommentPolicy::delete`
- [x] Deletes the row and decrements comment count reactively
- **Pest tests (`tests/Feature/Comments/DeleteCommentTest.php`):**
    - [x] `author can delete their own comment`
    - [x] `non-author cannot delete someone else's comment (403)`
    - [x] `comment count decreases after deletion`

---

