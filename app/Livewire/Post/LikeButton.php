<?php

namespace App\Livewire\Post;

use App\Models\Like;
use App\Models\Post;
use Livewire\Component;

class LikeButton extends Component
{
    public Post $post;

    public bool $isLiked = false;

    public int $likesCount = 0;

    public function mount(Post $post): void
    {
        $this->post = $post;
        $this->likesCount = $post->likes_count ?? $post->likes()->count();
        $this->isLiked = auth()->check()
            && $post->likes->contains('user_id', auth()->id());
    }

    public function toggle(): void
    {
        if (! auth()->check()) {
            $this->redirectRoute('login', navigate: true);

            return;
        }

        if ($this->isLiked) {
            Like::where('user_id', auth()->id())
                ->where('post_id', $this->post->id)
                ->delete();

            $this->isLiked = false;
            $this->likesCount = max(0, $this->likesCount - 1);

            return;
        }

        Like::firstOrCreate([
            'user_id' => auth()->id(),
            'post_id' => $this->post->id,
        ]);

        $this->isLiked = true;
        $this->likesCount = Like::where('post_id', $this->post->id)->count();
    }

    public function render()
    {
        return view('livewire.post.like-button');
    }
}
