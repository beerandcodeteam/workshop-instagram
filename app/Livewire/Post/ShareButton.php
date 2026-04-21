<?php

namespace App\Livewire\Post;

use App\Models\InteractionType;
use App\Models\Post;
use App\Models\PostInteraction;
use Livewire\Component;

class ShareButton extends Component
{
    public Post $post;

    public function mount(Post $post): void
    {
        $this->post = $post;
    }

    public function share(): void
    {
        if (! auth()->check()) {
            $this->redirectRoute('login', navigate: true);

            return;
        }

        $type = InteractionType::where('slug', 'share')->first();

        if ($type !== null) {
            PostInteraction::create([
                'user_id' => auth()->id(),
                'post_id' => $this->post->id,
                'interaction_type_id' => $type->id,
                'weight' => $type->default_weight,
                'created_at' => now(),
            ]);
        }

        $this->dispatch('post.shared', url: route('feed').'#post-'.$this->post->id);
    }

    public function render()
    {
        return view('livewire.post.share-button');
    }
}
