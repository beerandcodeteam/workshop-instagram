<?php

namespace App\Livewire\Post;

use App\Models\Post;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
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
