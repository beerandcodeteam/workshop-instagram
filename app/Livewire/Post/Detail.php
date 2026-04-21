<?php

namespace App\Livewire\Post;

use App\Livewire\Forms\CommentForm;
use App\Models\Comment;
use App\Models\Post;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

class Detail extends Component
{
    public ?Post $post = null;

    public bool $open = false;

    public CommentForm $form;

    #[On('open-post-detail')]
    public function openDetail(int $postId): void
    {
        $this->form->reset();
        $this->resetErrorBag();

        $this->post = Post::with(['author', 'type', 'media'])
            ->withCount(['likes', 'comments'])
            ->findOrFail($postId);

        $this->open = true;
    }

    public function closeModal(): void
    {
        $this->open = false;
        $this->post = null;
        $this->form->reset();
        $this->resetErrorBag();
    }

    public function addComment(): void
    {
        if ($this->post === null) {
            return;
        }

        if (! auth()->check()) {
            $this->redirectRoute('login', navigate: true);

            return;
        }

        $this->form->validate();

        Comment::create([
            'user_id' => auth()->id(),
            'post_id' => $this->post->id,
            'body' => $this->form->body,
        ]);

        $this->form->reset();
        $this->resetErrorBag();

        $this->post->loadCount('comments');

        $this->dispatch('comment.added', postId: $this->post->id);
    }

    public function deleteComment(int $commentId): void
    {
        if ($this->post === null) {
            return;
        }

        $comment = Comment::findOrFail($commentId);

        $this->authorize('delete', $comment);

        $comment->delete();

        $this->post->loadCount('comments');

        $this->dispatch('comment.deleted', postId: $this->post->id);
    }

    #[Computed]
    public function mediaUrls(): array
    {
        if ($this->post === null) {
            return [];
        }

        $disk = Storage::disk(config('filesystems.default'));

        return $this->post->media
            ->sortBy('sort_order')
            ->values()
            ->map(fn ($media) => $disk->url($media->file_path))
            ->all();
    }

    #[Computed]
    public function comments()
    {
        if ($this->post === null) {
            return collect();
        }

        return $this->post
            ->comments()
            ->with('author')
            ->orderBy('created_at')
            ->get();
    }

    public function render()
    {
        return view('livewire.post.detail');
    }
}
