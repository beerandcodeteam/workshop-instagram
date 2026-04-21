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

    #[On('post.deleted')]
    public function onPostDeleted(): void
    {
        //
    }

    #[On('post.updated')]
    public function onPostUpdated(): void
    {
        //
    }

    public function render()
    {
        $viewerCentroid = auth()->user()?->embedding;

        $query = Post::query()
            ->select('posts.*')
            ->with(['author', 'type', 'media', 'likes:id,post_id,user_id'])
            ->withCount(['likes', 'comments'])
            ->join('post_embeddings', 'post_embeddings.post_id', '=', 'posts.id');

        if ($viewerCentroid) {
            $literal = '['.implode(',', $viewerCentroid).']';
            $query->orderByRaw('post_embeddings.embedding <=> ?::vector', [$literal]);
        } else {
            $query->latest('posts.created_at')->latest('posts.id');
        }

        $totalPosts = Post::whereHas('postEmbeddings')->count();

        $posts = $query->limit($this->perPage)->get();

        return view('pages.feed.index', [
            'posts' => $posts,
            'hasMorePages' => $totalPosts > $posts->count(),
        ]);
    }
}
