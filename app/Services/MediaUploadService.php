<?php

namespace App\Services;

use App\Models\Post;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class MediaUploadService
{
    public function storeImage(UploadedFile $file, int $postId, int $sortOrder): string
    {
        $directory = "posts/{$postId}/images";
        $filename = $sortOrder.'-'.uniqid().'.'.$file->getClientOriginalExtension();

        return $file->storeAs($directory, $filename, $this->disk());
    }

    public function storeVideo(UploadedFile $file, int $postId): string
    {
        $directory = "posts/{$postId}/videos";
        $filename = uniqid().'.'.$file->getClientOriginalExtension();

        return $file->storeAs($directory, $filename, $this->disk());
    }

    public function delete(string $path): void
    {
        Storage::disk($this->disk())->delete($path);
    }

    public function deleteForPost(Post $post): void
    {
        foreach ($post->media as $media) {
            $this->delete($media->file_path);
        }
    }

    private function disk(): string
    {
        return config('filesystems.default');
    }
}
