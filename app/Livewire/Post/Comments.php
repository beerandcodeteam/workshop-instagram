<?php

namespace App\Livewire\Post;

use App\Livewire\Forms\CommentForm;
use App\Models\Comment;
use App\Models\Post;
use Livewire\Component;

class Comments extends Component
{
    public Post $post;

    public CommentForm $form;

    public function mount(Post $post): void
    {
        $this->post = $post;
    }

    public function addComment(): void
    {
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

        $this->dispatch('comment.added', postId: $this->post->id);
    }

    public function deleteComment(int $commentId): void
    {
        $comment = Comment::findOrFail($commentId);

        $this->authorize('delete', $comment);

        $comment->delete();

        $this->dispatch('comment.deleted', postId: $this->post->id);
    }

    public function render()
    {
        $comments = $this->post
            ->comments()
            ->with('author')
            ->orderBy('created_at')
            ->get();

        return view('livewire.post.comments', [
            'comments' => $comments,
        ]);
    }
}
