<?php

namespace App\Livewire\Pages\Feed;

use App\Models\Post;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Feed')]
class Index extends Component
{
    public int $perPage = 10;

    public function loadMore(): void
    {
        $this->perPage += 10;
    }

    #[On('post.created')]
    public function onPostCreated(): void
    {
        //
    }

    public function render()
    {
        $totalPosts = Post::count();

        $posts = Post::with([
            'author',
            'type',
            'media',
            'likes:id,post_id,user_id',
        ])
            ->withCount(['likes', 'comments'])
            ->latest()
            ->limit($this->perPage)
            ->get();

        return view('pages.feed.index', [
            'posts' => $posts,
            'hasMorePages' => $totalPosts > $posts->count(),
        ]);
    }
}
