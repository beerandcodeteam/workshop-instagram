## Phase 4 — File Storage (MinIO / S3)

### 4.1 Local MinIO service
- [x] Add MinIO service (and bucket-provisioning helper) to `compose.yaml`
- [x] Add env vars to `.env.example`: `FILESYSTEM_DISK=s3`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`, `AWS_ENDPOINT`, `AWS_USE_PATH_STYLE_ENDPOINT=true`, `AWS_URL`
- [x] Install `league/flysystem-aws-s3-v3` via Sail composer
- [x] Configure `config/filesystems.php` `s3` disk to consume the MinIO endpoint in local/dev

### 4.2 Media upload service
- [x] `App\Services\MediaUploadService` with methods:
    - `storeImage(UploadedFile $file, int $postId, int $sortOrder): string` — returns stored path
    - `storeVideo(UploadedFile $file, int $postId): string`
    - `delete(string $path): void` (and a `deleteForPost(Post $post)`)
- [x] Uses `Storage::disk(config('filesystems.default'))`
- **Pest tests (`tests/Feature/Services/MediaUploadServiceTest.php`) using `Storage::fake()`:**
    - [x] `storeImage writes to the configured disk and returns a path`
    - [x] `storeVideo writes to the configured disk and returns a path`
    - [x] `delete removes the file from the disk`

---

