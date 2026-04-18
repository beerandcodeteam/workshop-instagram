<?php

namespace App\Livewire\Post;

use App\Models\Post;
use App\Services\MediaUploadService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

class Card extends Component
{
    public Post $post;

    public bool $showComments = false;

    public function mount(Post $post): void
    {
        $this->post = $post;
    }

    public function toggleComments(): void
    {
        $this->showComments = ! $this->showComments;
    }

    #[On('post.updated')]
    public function onPostUpdated(int $postId): void
    {
        if ($this->post->id === $postId) {
            $this->post->refresh();
        }
    }

    public function deletePost(MediaUploadService $mediaUploadService): void
    {
        $this->authorize('delete', $this->post);

        DB::transaction(function () use ($mediaUploadService) {
            $mediaUploadService->deleteForPost($this->post);
            $this->post->delete();
        });

        $this->dispatch('post.deleted', postId: $this->post->id);
    }

    #[Computed]
    public function canManage(): bool
    {
        return auth()->check() && auth()->id() === $this->post->user_id;
    }

    #[Computed]
    public function mediaUrls(): array
    {
        $disk = Storage::disk(config('filesystems.default'));

        return $this->post->media
            ->sortBy('sort_order')
            ->values()
            ->map(fn ($media) => $disk->url($media->file_path))
            ->all();
    }

    public function render()
    {
        return view('livewire.post.card');
    }
}
