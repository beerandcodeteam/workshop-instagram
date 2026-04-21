<?php

namespace App\Livewire\Pages\Feed;

use App\Models\Post;
use App\Services\Recommendation\RecommendationService;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Feed')]
class Index extends Component
{
    public int $perPage = 10;

    /** @var array<int, int> */
    public array $postIds = [];

    public bool $hasMorePages = true;

    public function mount(RecommendationService $recommendationService): void
    {
        $this->loadBatch($recommendationService, $this->perPage);
    }

    public function loadMore(RecommendationService $recommendationService): void
    {
        $before = count($this->postIds);
        $this->perPage += 10;
        $this->loadBatch($recommendationService, 10);

        if (count($this->postIds) === $before) {
            $this->hasMorePages = false;
        }
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
        $posts = $this->postIds === []
            ? new Collection
            : $this->hydratePosts($this->postIds);

        return view('pages.feed.index', [
            'posts' => $posts,
            'hasMorePages' => $this->hasMorePages,
        ]);
    }

    private function loadBatch(RecommendationService $recommendationService, int $size): void
    {
        $user = auth()->user();

        $fresh = $recommendationService->feedFor($user, page: 1, pageSize: $size);

        if ($fresh->isEmpty()) {
            $this->hasMorePages = false;

            return;
        }

        foreach ($fresh as $post) {
            if (! in_array($post->id, $this->postIds, true)) {
                $this->postIds[] = $post->id;
            }
        }

        if ($fresh->count() < $size) {
            $this->hasMorePages = false;
        }
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, Post>
     */
    private function hydratePosts(array $ids): Collection
    {
        $ordering = array_flip($ids);

        return Post::query()
            ->with(['author', 'type', 'media', 'likes:id,post_id,user_id'])
            ->withCount(['likes', 'comments'])
            ->whereIn('id', $ids)
            ->get()
            ->sortBy(fn (Post $post) => $ordering[$post->id] ?? PHP_INT_MAX)
            ->values();
    }
}
