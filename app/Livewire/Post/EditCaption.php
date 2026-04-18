<?php

namespace App\Livewire\Post;

use App\Livewire\Forms\CaptionPostForm;
use App\Models\Post;
use Livewire\Component;

class EditCaption extends Component
{
    public Post $post;

    public bool $open = false;

    public CaptionPostForm $form;

    public function mount(Post $post): void
    {
        $this->post = $post;
    }

    public function openModal(): void
    {
        $this->authorize('update', $this->post);

        $this->form->body = $this->post->body ?? '';
        $this->resetErrorBag();
        $this->open = true;
    }

    public function closeModal(): void
    {
        $this->form->reset();
        $this->resetErrorBag();
        $this->open = false;
    }

    public function save(): void
    {
        $this->authorize('update', $this->post);

        $this->form->validate();

        $this->post->update(['body' => $this->form->body]);
        $this->post->refresh();

        $this->dispatch('post.updated', postId: $this->post->id);

        $this->open = false;
        $this->resetErrorBag();
    }

    public function render()
    {
        return view('livewire.post.edit-caption');
    }
}
