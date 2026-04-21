<?php

namespace App\Livewire\Post;

use App\Models\Post;
use App\Models\RecommendationLog;
use Livewire\Attributes\Computed;
use Livewire\Component;

class WhyDidISeeThis extends Component
{
    public Post $post;

    public bool $open = false;

    public function mount(Post $post): void
    {
        $this->post = $post;
    }

    public function openModal(): void
    {
        $this->open = true;
    }

    public function closeModal(): void
    {
        $this->open = false;
    }

    #[Computed]
    public function trace(): ?RecommendationLog
    {
        if (! auth()->check()) {
            return null;
        }

        return RecommendationLog::query()
            ->with('source')
            ->where('user_id', auth()->id())
            ->where('post_id', $this->post->id)
            ->whereNull('filtered_reason')
            ->latest('created_at')
            ->first();
    }

    #[Computed]
    public function reason(): string
    {
        $trace = $this->trace();

        if ($trace === null) {
            return 'Não temos mais essa informação — os rastros de recomendação são guardados por apenas 7 dias.';
        }

        $slug = $trace->source?->slug;

        if ($slug === null) {
            return 'Esse post chegou até você pelo nosso feed, mas não temos detalhes da fonte agora.';
        }

        $map = config('recommendation.source_reasons', []);
        $phrase = $map[$slug] ?? 'foi escolhido pelo nosso algoritmo de recomendação';

        return "Você está vendo esse post porque ele {$phrase}.";
    }

    public function render()
    {
        return view('livewire.post.why-did-i-see-this');
    }
}
